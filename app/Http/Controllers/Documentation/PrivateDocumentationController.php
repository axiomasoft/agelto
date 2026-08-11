<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documentation;

use App\Http\Controllers\Controller;
use App\Services\Documentation\PrivateDocumentationLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PrivateDocumentationController extends Controller
{
    public function __construct(private readonly PrivateDocumentationLocator $locator) {}

    public function __invoke(?string $path = null): BinaryFileResponse
    {
        $this->authorizeThroughP43Placeholder();

        $asset = $this->locator->locate(requestedPath: $path);
        $response = response()->file(file: $asset->absolutePath);

        foreach ($asset->responseHeaders() as $name => $value) {
            $response->headers->set(key: $name, values: $value);
        }

        return $response;
    }

    private function authorizeThroughP43Placeholder(): void
    {
        if (config('documentation.authorization_placeholder') !== true) {
            abort(403);
        }
    }
}
