@php
    $v = (string) $value;
    $cls = match ($v) {
        'settled', 'delivered', 'active' => 'badge-success-soft',
        'issued', 'redeemed' => 'badge-info-soft',
        'pending' => 'badge-warning-soft',
        'failed' => 'badge-danger-soft',
        default => 'badge-theme',
    };
@endphp
<span class="{{ $cls }}">{{ $v }}</span>
