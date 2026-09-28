<?php

it('responde el chequeo de salud', function () {
    $this->get('/up')->assertOk();
});
