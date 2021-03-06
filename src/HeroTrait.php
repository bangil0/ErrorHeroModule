<?php

declare(strict_types=1);

namespace ErrorHeroModule;

use ErrorException;
use ErrorHeroModule\Handler\Logging;
use Laminas\Mvc\MvcEvent;
use Psr\Http\Message\ServerRequestInterface;
use Webmozart\Assert\Assert;

use function error_get_last;
use function error_reporting;
use function func_get_args;
use function ini_set;
use function is_array;
use function ob_end_flush;
use function ob_get_clean;
use function ob_get_level;
use function ob_start;
use function register_shutdown_function;
use function set_error_handler;
use function strpos;

use const E_ALL;
use const E_STRICT;

trait HeroTrait
{
    /** @var array */
    private $errorHeroModuleConfig;

    /** @var Logging */
    private $logging;

    /** @var string */
    private $result = '';

    public function phpError(): void
    {
        if ($this instanceof Listener\Mvc) {
            Assert::count($args = func_get_args(), 1);
            Assert::isInstanceOf($this->mvcEvent = $args[0], MvcEvent::class);
        }

        if (! $this->errorHeroModuleConfig['display-settings']['display_errors']) {
            error_reporting(E_ALL | E_STRICT);
            ini_set('display_errors', '0');
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_start([$this, 'phpFatalErrorHandler']);
        register_shutdown_function([$this, 'execOnShutdown']);
        set_error_handler([$this, 'phpErrorHandler']);
    }

    private static function isUncaught(array $error): bool
    {
        return 0 === strpos($error['message'], 'Uncaught');
    }

    /** @param string $buffer */
    public function phpFatalErrorHandler($buffer): string
    {
        $error = error_get_last();
        if (! $error) {
            return $buffer;
        }

        return self::isUncaught($error) || $this->result === ''
            ? $buffer
            : $this->result;
    }

    public function execOnShutdown(): void
    {
        $error = error_get_last();
        if (! $error) {
            return;
        }

        if (self::isUncaught($error)) {
            return;
        }

        $errorException = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);

        // Laminas Mvc project
        if ($this instanceof Listener\Mvc) {
            Assert::isInstanceOf($this->mvcEvent, MvcEvent::class);

            ob_start();
            $this->mvcEvent->setParam('exception', $errorException);
            $this->exceptionError($this->mvcEvent);
            $this->result = (string) ob_get_clean();

            return;
        }

        // Mezzio project
        Assert::implementsInterface($this->request, ServerRequestInterface::class);

        $result       = $this->exceptionError($errorException);
        $this->result = (string) $result->getBody();
    }

    /**
     * @throws ErrorException When php error happen and error type is not excluded in the config.
     */
    public function phpErrorHandler(int $errorType, string $errorMessage, string $errorFile, int $errorLine): void
    {
        if (! (error_reporting() & $errorType)) {
            return;
        }

        foreach ($this->errorHeroModuleConfig['display-settings']['exclude-php-errors'] as $excludePhpError) {
            if ($errorType === $excludePhpError) {
                return;
            }

            if (
                is_array($excludePhpError)
                && $excludePhpError[0] === $errorType
                && $excludePhpError[1] === $errorMessage
            ) {
                return;
            }
        }

        throw new ErrorException($errorMessage, 0, $errorType, $errorFile, $errorLine);
    }
}
