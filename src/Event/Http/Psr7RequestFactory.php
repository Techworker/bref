<?php declare(strict_types=1);

namespace Bref\Event\Http;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Riverline\MultiPartParser\Part;
use RuntimeException;

/**
 * Creates PSR-7 requests from API Gateway or ALB events.
 *
 * @internal
 */
final class Psr7RequestFactory
{
    /**
     * Create a PSR-7 server request from an AWS Lambda HTTP event.
     */
    public static function fromEvent(HttpRequestEvent $event): ServerRequestInterface
    {
        [$files, $parsedBody] = self::parseBodyAndUploadedFiles($event);

        $server = [
            'SERVER_PROTOCOL' => $event->getProtocolVersion(),
            'REQUEST_METHOD' => $event->getMethod(),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'QUERY_STRING' => $event->getQueryString(),
            'DOCUMENT_ROOT' => getcwd(),
            'REQUEST_URI' => $event->getUri(),
        ];

        $headers = $event->getHeaders();
        if (isset($headers['Host'])) {
            $server['HTTP_HOST'] = $headers['Host'];
        }

        $request = new ServerRequest(
            $event->getMethod(),
            $event->getUri(),
            $event->getHeaders(),
            $event->getBody(),
            $event->getProtocolVersion(),
            $server
        );

        return $request->withUploadedFiles($files)
            ->withCookieParams($event->getCookies())
            ->withQueryParams($event->getQueryParameters())
            ->withParsedBody($parsedBody);
    }

    private static function parseBodyAndUploadedFiles(HttpRequestEvent $event): array
    {
        $bodyString = $event->getBody();
        $files = [];
        $parsedBody = null;
        $contentType = $event->getContentType();
        if ($contentType !== null && $event->getMethod() === 'POST') {
            if ($contentType === 'application/x-www-form-urlencoded') {
                parse_str($bodyString, $parsedBody);
            } else {
                $document = new Part("Content-type: $contentType\r\n\r\n" . $bodyString);
                if ($document->isMultiPart()) {
                    $parsedBody = [];
                    foreach ($document->getParts() as $part) {
                        if ($part->isFile()) {
                            $tmpPath = tempnam(sys_get_temp_dir(), 'bref_upload_');
                            if ($tmpPath === false) {
                                throw new RuntimeException('Unable to create a temporary directory');
                            }
                            file_put_contents($tmpPath, $part->getBody());
                            $file = new UploadedFile($tmpPath, filesize($tmpPath), UPLOAD_ERR_OK, $part->getFileName(), $part->getMimeType());

                            self::parseKeyAndInsertValueInArray($files, $part->getName(), $file);
                        } else {
                            self::parseKeyAndInsertValueInArray($parsedBody, $part->getName(), $part->getBody());
                        }
                    }
                }
            }
        }
        return [$files, $parsedBody];
    }

    /**
     * Parse a string key like "files[id_cards][jpg][]" and do $array['files']['id_cards']['jpg'][] = $value
     *
     * @param mixed $value
     */
    private static function parseKeyAndInsertValueInArray(array &$array, string $key, $value): void
    {
        if (strpos($key, '[') === false) {
            $array[$key] = $value;

            return;
        }

        $parts = explode('[', $key); // files[id_cards][jpg][] => [ 'files',  'id_cards]', 'jpg]', ']' ]
        $pointer = &$array;

        foreach ($parts as $k => $part) {
            if ($k === 0) {
                $pointer = &$pointer[$part];

                continue;
            }

            // Skip two special cases:
            // [[ in the key produces empty string
            // [test : starts with [ but does not end with ]
            if ($part === '' || substr($part, -1) !== ']') {
                // Malformed key, we use it "as is"
                $array[$key] = $value;

                return;
            }

            $part = substr($part, 0, -1); // The last char is a ] => remove it to have the real key

            if ($part === '') { // [] case
                $pointer = &$pointer[];
            } else {
                $pointer = &$pointer[$part];
            }
        }

        $pointer = $value;
    }
}
