<?php

namespace App\Enum;

/**
 * Lieu de réalisation d'une prestation : au domicile du client ou à l'atelier de l'artisan.
 */
enum Location: string
{
    case HOME = 'HOME';
    case WORKSHOP = 'WORKSHOP';
}
