<?php

namespace Tests\Feature;

use App\Support\Money;
use App\Support\PrivateMode;
use Tests\TestCase;

class MoneyFormatTest extends TestCase
{
    public function test_amounts_use_the_french_euro_format_by_default(): void
    {
        $this->assertSame('1 234,56 €', money(123456));
        $this->assertSame('-800,00 €', money(-80000));
        $this->assertSame('+50,00 €', money(5000, sign: true));
        $this->assertSame('0,00 €', money(0, sign: true));
        $this->assertSame('14 388 €', money(1438800, decimals: 0));
    }

    public function test_symbol_and_number_format_come_from_the_config(): void
    {
        config(['finance.currency_symbol' => '$', 'finance.money_format' => 'en']);

        $this->assertSame('$1,234.56', money(123456));
        $this->assertSame('-$800.00', money(-80000));
        $this->assertSame('+$50.00', money(5000, sign: true));
        $this->assertSame('$****', Money::masked());

        config(['finance.currency_symbol' => 'CHF', 'finance.money_format' => 'de']);

        $this->assertSame('1.234,56 CHF', money(123456));
    }

    public function test_without_a_configured_format_amounts_follow_the_app_locale(): void
    {
        config(['finance.money_format' => null]);

        $this->assertSame('€1,234.56', money(123456));

        app()->setLocale('fr');
        $this->assertSame('1 234,56 €', money(123456));

        app()->setLocale('es'); // locale sans format dédié : anglais
        $this->assertSame('€1,234.56', money(123456));
    }

    public function test_private_mode_masks_amounts_in_the_configured_format(): void
    {
        config(['finance.currency_symbol' => '$', 'finance.money_format' => 'en']);
        app(PrivateMode::class)->toggle();

        $this->assertSame('$****', money(123456));
    }
}
