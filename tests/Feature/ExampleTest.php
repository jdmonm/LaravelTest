<?php

it('redirects visitors to the application entry point', function () {
    $this->get('/')->assertRedirect(route('dashboard'));
});
