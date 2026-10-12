<?php

declare(strict_types=1);

namespace Stejac\GuardedChatBundle\Controller;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Stejac\GuardedChatBundle\Dto\ChatMessageRequest;
use Stejac\GuardedChatBundle\Dto\ChatSessionRequest;
use Stejac\GuardedChatBundle\Dto\SessionLimits;
use Stejac\GuardedChatBundle\Entity\ChatSession;
use Stejac\GuardedChatBundle\Exception\ChatBudgetExhaustedException;
use Stejac\GuardedChatBundle\Exception\ChatGatewayException;
use Stejac\GuardedChatBundle\Exception\ChatMessageLimitReachedException;
use Stejac\GuardedChatBundle\Exception\InvalidChatRequestException;
use Stejac\GuardedChatBundle\Exception\InvalidChatSessionTokenException;
use Stejac\GuardedChatBundle\Exception\UnexpectedChatFailureException;
use Stejac\GuardedChatBundle\Guard\HumanVerifierInterface;
use Stejac\GuardedChatBundle\Http\ChatRateLimiter;
use Stejac\GuardedChatBundle\Repository\ChatSessionRepository;
use Stejac\GuardedChatBundle\Security\ChatSessionTokenSigner;
use Stejac\GuardedChatBundle\Service\ChatAvailability;
use Stejac\GuardedChatBundle\Service\ChatMessageHandler;
use Stejac\GuardedChatBundle\Service\ChatSessionStarter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The single public gatekeeper of the chat: no other route reaches the model gateway. `startSession`
 * never triggers a model call, `message` is the only place that does. Import the routes with a prefix
 * of your choice (see the README); the error codes below are part of the widget contract.
 */
final readonly class ChatController
{
    public const string SESSION_TOKEN_KEY = 'guarded_chat.session_token';

    private const string TRANSLATION_DOMAIN = 'guarded_chat';

    /** @param list<string> $locales */
    public function __construct(
        private ChatSessionStarter $sessionStarter,
        private ChatSessionRepository $sessions,
        private ChatSessionTokenSigner $signer,
        private ChatMessageHandler $handler,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private ChatAvailability $availability,
        private HumanVerifierInterface $humanVerifier,
        private ChatRateLimiter $rateLimiter,
        private TranslatorInterface $translator,
        private SessionLimits $limits,
        private RateLimiterFactory $sessionLimiter,
        private RateLimiterFactory $messageLimiter,
        private RateLimiterFactory $messageDailyLimiter,
        private array $locales,
        private string $defaultLocale,
        private int $maxMessageLength,
    ) {
    }

    #[Route('/session', name: 'guarded_chat_start_session', methods: ['POST'])]
    public function startSession(Request $request): JsonResponse
    {
        $this->assertEnabled();

        try {
            $payload = ChatSessionRequest::fromPayload($this->decode($request), $this->locales, $this->defaultLocale);
        } catch (InvalidChatRequestException $e) {
            return $this->invalidRequestResponse($e, $this->defaultLocale);
        }

        $limited = $this->rateLimiter->reject($this->sessionLimiter, $request, $this->trans('error.rate_limited_session', $payload->locale));
        if (null !== $limited) {
            return $limited;
        }

        // The honeypot is checked before the verifier so an obvious bot never costs a verification
        // round-trip. Both rejections look identical from the outside.
        if ('' !== $payload->website || !$this->humanVerifier->verify($payload->verificationToken)) {
            return new JsonResponse([
                'error' => 'verification_failed',
                'message' => $this->trans('error.verification_failed', $payload->locale),
            ], Response::HTTP_FORBIDDEN);
        }

        $request->getSession()->set(self::SESSION_TOKEN_KEY, $this->sessionStarter->start());

        return new JsonResponse(['status' => 'created'], Response::HTTP_CREATED);
    }

    #[Route('/message', name: 'guarded_chat_message', methods: ['POST'])]
    public function message(Request $request): Response
    {
        $this->assertEnabled();

        try {
            $payload = ChatMessageRequest::fromPayload($this->decode($request), $this->locales, $this->defaultLocale, $this->maxMessageLength);
        } catch (InvalidChatRequestException $e) {
            return $this->invalidRequestResponse($e, $this->defaultLocale);
        }

        $limited = $this->rateLimiter->reject($this->messageLimiter, $request, $this->trans('error.rate_limited_message', $payload->locale))
            ?? $this->rateLimiter->reject($this->messageDailyLimiter, $request, $this->trans('error.rate_limited_daily', $payload->locale));
        if (null !== $limited) {
            return $limited;
        }

        $now = $this->clock->now();
        $session = $this->resolveSession($request, $now);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        $stream = $this->startStreamOrError($session, $payload, $now);

        return $stream instanceof JsonResponse ? $stream : $this->streamResponse($stream, $payload->locale);
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->isEnabled()) {
            throw new NotFoundHttpException();
        }
    }

    /** @return array<mixed> */
    private function decode(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            throw new InvalidChatRequestException('The request body must be a JSON object.');
        }

        return $payload;
    }

    private function invalidRequestResponse(InvalidChatRequestException $e, string $locale): JsonResponse
    {
        return new JsonResponse([
            'error' => 'invalid_request',
            'message' => $this->trans('error.invalid_request', $locale),
            'detail' => $e->getMessage(),
        ], Response::HTTP_BAD_REQUEST);
    }

    private function resolveSession(Request $request, \DateTimeImmutable $now): ChatSession|JsonResponse
    {
        $token = $request->getSession()->get(self::SESSION_TOKEN_KEY);
        if (!\is_string($token)) {
            return new JsonResponse(['error' => 'invalid_token'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $sessionId = $this->signer->verify($token);
        } catch (InvalidChatSessionTokenException) {
            $request->getSession()->remove(self::SESSION_TOKEN_KEY);

            return new JsonResponse(['error' => 'invalid_token'], Response::HTTP_UNAUTHORIZED);
        }

        $session = $this->sessions->find($sessionId);
        if (!$session instanceof ChatSession || $session->isExpired($now, $this->limits)) {
            return new JsonResponse(['error' => 'session_expired'], Response::HTTP_UNAUTHORIZED);
        }

        return $session;
    }

    /**
     * Starts the reply stream and pulls its first event here, while an HTTP error response is still
     * possible: the model is only reached on the first advance of the generator, and once the streamed
     * response has started a 200 is already on the wire.
     *
     * @return \Generator<int, \Stejac\GuardedChatBundle\Dto\ChatReplyEvent>|JsonResponse
     */
    private function startStreamOrError(ChatSession $session, ChatMessageRequest $payload, \DateTimeImmutable $now): \Generator|JsonResponse
    {
        try {
            $stream = $this->handler->handle($session, $payload->message, $now, $payload->locale);
            $stream->current();

            return $stream;
        } catch (ChatMessageLimitReachedException|ChatGatewayException $e) {
            return $this->failureResponse($e, $payload->locale);
        } catch (\Throwable $e) {
            // Everything before the first yield runs here: persisting the visitor's turn and loading
            // the prompt. A failure there would otherwise escape as a bare 500 with an HTML body,
            // which the widget cannot turn into a readable message.
            $this->logger->error('Chat pipeline failed before streaming', [
                'exception' => new UnexpectedChatFailureException('Chat pipeline failed before streaming started.', 0, $e),
            ]);

            return $this->unavailableResponse($payload->locale);
        }
    }

    private function failureResponse(ChatMessageLimitReachedException|ChatGatewayException $e, string $locale): JsonResponse
    {
        if ($e instanceof ChatMessageLimitReachedException) {
            return new JsonResponse([
                'error' => 'message_limit_reached',
                // Not "reload the page": the stored token keeps pointing at the exhausted session, so a
                // reload alone would hit this same 429 again. The widget clears its session flag on this
                // error code, which is what actually opens a fresh chat.
                'message' => $this->trans('error.message_limit_reached', $locale),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($e instanceof ChatBudgetExhaustedException) {
            $this->logger->error('Chat gateway budget exhausted', ['exception' => $e]);

            return new JsonResponse([
                'error' => 'budget_exhausted',
                'message' => $this->trans('error.budget_exhausted', $locale),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $this->logger->error('Chat gateway unavailable', ['exception' => $e]);

        return $this->unavailableResponse($locale);
    }

    private function unavailableResponse(string $locale): JsonResponse
    {
        return new JsonResponse([
            'error' => 'gateway_unavailable',
            'message' => $this->trans('error.unavailable', $locale),
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /** @param \Generator<int, \Stejac\GuardedChatBundle\Dto\ChatReplyEvent> $stream */
    private function streamResponse(\Generator $stream, string $locale): StreamedResponse
    {
        return new StreamedResponse(function () use ($stream, $locale): void {
            try {
                // Not a foreach: the generator was already advanced once in startStreamOrError() and
                // foreach's implicit rewind() throws once a generator has moved past its first element.
                // valid()/next() never rewinds, so the already-pulled first event is emitted exactly once.
                for (; $stream->valid(); $stream->next()) {
                    $event = $stream->current();
                    if (null !== $event->action) {
                        $this->writeSseEvent('action', ['type' => $event->action]);
                    } elseif (null !== $event->text) {
                        $this->writeSseEvent('text', ['text' => $event->text]);
                    }
                }
            } catch (\Throwable $e) {
                // Headers and the 200 status are already flushed by the time a failure can happen here,
                // so this can never become an HTTP error response: append a friendly fallback to the
                // partial output instead.
                $this->logger->error('Chat stream failed mid-response', ['exception' => $e]);
                $this->writeSseEvent('text', ['text' => $this->trans('error.unavailable_stream', $locale)]);
            }
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param array<string, string> $data */
    private function writeSseEvent(string $name, array $data): void
    {
        echo sprintf("event: %s\ndata: %s\n\n", $name, json_encode($data, \JSON_THROW_ON_ERROR));
        flush();
    }

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], self::TRANSLATION_DOMAIN, $locale);
    }
}
