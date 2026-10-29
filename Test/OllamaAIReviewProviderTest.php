<?php
declare(strict_types=1);
namespace GDO\Badger\Test;

use GDO\Badger\OllamaAIReviewProvider;
use PHPUnit\Framework\TestCase;

final class OllamaAIReviewProviderTest extends TestCase
{
    public function testPayloadAndResponse(): void
    {
        $request = ['name' => 'Badge', 'rubric' => 'Help', 'prompt' => 'Evaluate', 'message' => 'I helped', 'document_mime' => 'image/png'];
        $payload = OllamaAIReviewProvider::payload('test-model', $request, 'image bytes');
        self::assertFalse($payload['stream']);
        self::assertSame('json', $payload['format']);
        self::assertSame(base64_encode('image bytes'), $payload['messages'][1]['images'][0]);
        $response = json_encode(['done' => true, 'message' => ['content' => json_encode(['decision' => 'inconclusive', 'rationale' => 'Not enough information'])]]);
        self::assertSame(['model' => 'test-model', 'decision' => 'inconclusive', 'rationale' => 'Not enough information'], OllamaAIReviewProvider::response($response, 'test-model'));
    }

    public function testPdfFailsBeforeCallingProvider(): void
    {
        $this->expectExceptionMessage('Unsupported AI evidence type');
        OllamaAIReviewProvider::payload('test-model', ['document_mime' => 'application/pdf'], '%PDF-1.4');
    }

    public function testOnlyReviewInputsAreSent(): void
    {
        $payload = OllamaAIReviewProvider::payload('test-model', [
            'name' => 'Badge', 'rubric' => 'Rubric', 'prompt' => 'Criteria', 'message' => 'Claim',
            'recipient' => 'private-recipient', 'achievement' => 'private-id', 'unrelated_secret' => 'private-secret',
        ], null);
        $content = $payload['messages'][1]['content'];
        self::assertSame(['badge', 'rubric', 'criteria', 'document_criteria', 'claim'], array_keys(json_decode($content, true, 512, JSON_THROW_ON_ERROR)));
        self::assertStringNotContainsString('private-recipient', $content);
        self::assertStringNotContainsString('private-id', $content);
        self::assertStringNotContainsString('private-secret', $content);
        self::assertArrayNotHasKey('images', $payload['messages'][1]);
    }

    public function testMalformedAndUnfinishedResponsesFailClosed(): void
    {
        foreach ([
            '{}',
            json_encode(['done' => false, 'message' => ['content' => '{"decision":"approve","rationale":"ok"}']]),
            json_encode(['done' => true, 'message' => ['content' => '{"decision":"unknown","rationale":"ok"}']]),
            json_encode(['done' => true, 'message' => ['content' => '{"decision":"approve","rationale":" "}']]),
            json_encode(['done' => true, 'message' => ['content' => json_encode(['decision' => 'approve', 'rationale' => str_repeat('x', 4001)])]]),
        ] as $body)
        {
            try
            {
                OllamaAIReviewProvider::response($body, 'test-model');
                self::fail('Invalid provider output must not be accepted');
            }
            catch (\RuntimeException $ex)
            {
                self::assertSame('Invalid local AI response', $ex->getMessage());
            }
        }
    }

    public function testOversizedPromptFailsClosed(): void
    {
        $this->expectExceptionMessage('AI review input too large');
        OllamaAIReviewProvider::payload('test-model', ['prompt' => str_repeat('x', 65537)], null);
    }
}
