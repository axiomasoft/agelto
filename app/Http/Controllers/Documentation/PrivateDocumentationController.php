<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documentation;

use App\Guards\Admin\Permissions\DocumentationPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Documentation\PrivateDocumentationLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PrivateDocumentationController extends Controller
{
    public function __construct(private readonly PrivateDocumentationLocator $locator) {}

    public function __invoke(?string $path = null): BinaryFileResponse
    {
        $this->authorizeDocumentation();

        $asset = $this->locator->locate(requestedPath: $path);
        $response = response()->file(file: $asset->absolutePath);

        foreach ($asset->responseHeaders() as $name => $value) {
            $response->headers->set(key: $name, values: $value);
        }

        return $response;
    }

    private function authorizeDocumentation(): void
    {
        $user = request()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if (! $user->hasPermission(permission: DocumentationPermission::View, panelId: 'admin')) {
            abort(403);
        }
    }
}
