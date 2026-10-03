<?php
declare(strict_types=1);

namespace Panth\Hreflang\Test\Unit\Controller\Adminhtml\Hreflang;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Message\ManagerInterface;
use Panth\Hreflang\Test\Unit\DbStubTrait;
use PHPUnit\Framework\TestCase;

/**
 * Shared admin controller wiring: records the redirect target and flash messages.
 */
abstract class ControllerTestCase extends TestCase
{
    use DbStubTrait;

    protected array $redirect = [];
    protected array $messages = ['success' => [], 'error' => [], 'warning' => []];
    protected array $allowedResources = [];

    protected function context(array $params = [], array $post = []): Context
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            static fn(string $key, $default = null) => $params[$key] ?? $default
        );
        $request->method('getPostValue')->willReturn($post);

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(
            function (string $path, array $args = []) use ($redirect) {
                $this->redirect = [$path, $args];
                return $redirect;
            }
        );
        $factory = $this->createStub(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);

        $messages = $this->createStub(ManagerInterface::class);
        foreach (['success', 'error', 'warning'] as $type) {
            $messages->method('add' . ucfirst($type) . 'Message')->willReturnCallback(
                function ($message) use ($messages, $type) {
                    $this->messages[$type][] = (string) $message;
                    return $messages;
                }
            );
        }

        $allowed = &$this->allowedResources;
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static function ($resource) use (&$allowed) {
                return in_array($resource, $allowed, true);
            }
        );

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getAuthorization')->willReturn($authorization);
        return $context;
    }
}
