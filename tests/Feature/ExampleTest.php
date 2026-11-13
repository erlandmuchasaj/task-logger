<?php

declare(strict_types=1);

it('redirects guests from the panel to login', function (): void {
    $this->get('/app')->assertRedirect('/app/login');
});

it('has no self-registration route (NFR-01, FR-01)', function (): void {
    $this->get('/app/register')->assertNotFound();
    $this->get('/register')->assertNotFound();
});
