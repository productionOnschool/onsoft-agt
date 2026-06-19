<?php

namespace Onsoft\Agt\Enums;

enum EstadoPagamento: string
{
    case PAGO     = 'paid';
    case PARCIAL  = 'partial';
    case EXCEDIDO = 'overpaid';
    case CANCELADO = 'cancelled';
    case PENDENTE = 'pending';

    public function etiqueta(): string
    {
        return match($this) {
            self::PAGO      => 'Pago',
            self::PARCIAL   => 'Parcialmente Pago',
            self::EXCEDIDO  => 'Excedido',
            self::CANCELADO => 'Cancelado',
            self::PENDENTE  => 'Pendente',
        };
    }
}
