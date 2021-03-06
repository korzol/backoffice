<?php

declare(strict_types=1);

namespace App\BasicRum\TechnicalMetrics;

class TPage implements \App\BasicRum\Report\SelectableInterface
{
    public function getSelectDataFieldName(): string
    {
        return 't_page';
    }

    public function getSelectTableName(): string
    {
        return 'rum_data_flat';
    }
}
