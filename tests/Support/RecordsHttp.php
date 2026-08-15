<?php
/*
 * Created on   : Sat Aug 15 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : RecordsHttp.php
 * License      : MIT License
 * License Uri  : https://opensource.org/license/mit
 */

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\{Client as HttpClient, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle-Client mit vorbereiteten Antworten und Request-Mitschnitt — testet
 * den echten Request-Pfad des api-toolkit inklusive Auth-Headern.
 */
trait RecordsHttp {
    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    /**
     * @param list<Response> $responses
     */
    private function mockClient(array $responses): HttpClient {
        $history = new \ArrayObject;
        $this->history = $history;
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new HttpClient(['handler' => $stack]);
    }

    /**
     * Aufgezeichneter Request aus der Guzzle-History.
     */
    private function requestAt(int $index): RequestInterface {
        $request = $this->history[$index]['request'] ?? null;
        if (!$request instanceof RequestInterface) {
            $this->fail("Kein Request an Position {$index} aufgezeichnet");
        }

        return $request;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function bodyAt(int $index): array {
        /** @var array<array-key, mixed> $decoded */
        $decoded = json_decode((string) $this->requestAt($index)->getBody(), true);

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function jsonResponse(array $data, int $status = 200): Response {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
