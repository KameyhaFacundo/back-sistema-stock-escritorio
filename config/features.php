<?php

// Flags de features que se pueden prender/apagar por entorno sin tocar código.
return [
    // Kill switch de la verificación en dos pasos (2FA): si está en false, nadie
    // puede activarla, y el login ignora two_factor_enabled de los usuarios que
    // ya la tenían configurada (no los bloquea, pero no la exige tampoco). El
    // estado en la base no se borra — si se vuelve a poner en true, los usuarios
    // que ya la habían activado quedan protegidos de nuevo sin tener que reconfigurarla.
    'two_factor_enabled' => env('TWO_FACTOR_ENABLED', true),
];
