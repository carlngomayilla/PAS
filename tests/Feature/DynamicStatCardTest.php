<?php

namespace Tests\Feature;

use Tests\TestCase;

class DynamicStatCardTest extends TestCase
{
    public function test_stat_card_link_can_hide_empty_values(): void
    {
        $this->blade('<x-stat-card-link href="/" label="Carte vide" value="0" hide-when-empty />')
            ->assertDontSee('Carte vide');

        $this->blade('<x-stat-card-link href="/" label="Carte utile" value="3" hide-when-empty />')
            ->assertSee('Carte utile')
            ->assertSee('3');
    }

    public function test_ui_stat_card_can_hide_empty_values(): void
    {
        $this->blade('<x-ui.stat-card title="Stat vide" value="0" hide-when-empty />')
            ->assertDontSee('Stat vide');

        $this->blade('<x-ui.stat-card title="Stat utile" value="12" hide-when-empty />')
            ->assertSee('Stat utile')
            ->assertSee('12');
    }
}
