<?php
// src/Service/Counter.php
namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class Counter
{
    private const KEY = 'app.counter';

    public function __construct(private RequestStack $requestStack) {}

    private function session(): SessionInterface
    {
        $session = $this->requestStack->getSession();
        if (!$session) {
            throw new \RuntimeException('No active session. Did you enable "framework.session"?');
        }
        return $session;
    }

    public function get(): int
    {
        return (int) $this->session()->get(self::KEY, 0);
    }

    public function increment(): int
    {
        $v = $this->get() + 1;
        $this->session()->set(self::KEY, $v);
        return $v;
    }

    public function decrement(): int
    {
        $v = max(0, $this->get() - 1);
        $this->session()->set(self::KEY, $v);
        return $v;
    }

    public function reset(): void
    {
        $this->session()->set(self::KEY, 0);
    }
}
