<?php

declare(strict_types=1);

namespace CawlPayment\Controller\Front;

use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Front\BaseFrontController;

/**
 * Controller to serve payment method icons
 */
class AssetController extends BaseFrontController
{
    public function iconAction(string $filename): Response
    {
        // Sanitize filename to prevent directory traversal
        $filename = basename($filename);

        // Only allow SVG and PNG files
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['svg', 'png'])) {
            return new Response('Not Found', 404);
        }

        $filePath = __DIR__ . '/../../images/payment-methods/' . $filename;

        if (!file_exists($filePath)) {
            return new Response('Not Found', 404);
        }

        $content = file_get_contents($filePath);

        $contentType = $extension === 'svg' ? 'image/svg+xml' : 'image/png';

        $response = new Response($content, 200);
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Cache-Control', 'public, max-age=31536000');

        return $response;
    }
}
