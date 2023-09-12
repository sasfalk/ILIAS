<?php

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table as I;
/**
 * Class DataRetrieval
 *
 */
class DataRetrieval implements I\DataRetrieval
{
    protected \ilBiblAdminFactoryFacade $facade;

    public function __construct(protected \ILIAS\UI\Factory $ui_factory, protected \ILIAS\UI\Renderer $ui_renderer,ilBiblAdminFactoryFacade $facade)
    {
        $this->facade = $facade;
    }

    public function getRows(
        I\DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): \Generator {
        $records = $this->getRecords($order);
        foreach ($records as $idx => $record) {
            $row_id = (string)$record['id'];
            //$record['translation'] = $this->facade->translationFactory()->translate($record['translation']);
            //$record['standard'] = $record['standard']->isStandardField() ? 'standard' : 'custom';
            yield $row_builder->buildDataRow($row_id, $record);
        }
    }

    protected function getRecords(Order $order): array
    {
        $records = [
            ['position' => 10, 'id' => 0, 'identifier' => 'author','translation' => 'Author','standard' => 'Standard'],
            ['position' => 20, 'id' => 1, 'identifier' => 'editor','translation' => 'editor','standard' => 'Standard'],
            ['position' => 30, 'id' => 2, 'identifier' => 'isbn','translation' => 'ISSN/ISBN','standard' => 'Custom'],
            ['position' => 40, 'id' => 3, 'identifier' => 'edition','translation' => 'Edition','standard' => 'Standard'],
        ];

        //$records = $this->facade->fieldFactory()->filterAllFieldsForType($this->facade->type());

        list($order_field, $order_direction) = $order->join([], fn($ret, $key, $value) => [$key, $value]);
        usort($records, fn($a, $b) => $a[$order_field] <=> $b[$order_field]);
        if ($order_direction === 'DESC') {
            $records = array_reverse($records);
        }

        return $records;
    }

    public function getTotalRowCount(
        ?array $filter_data,
        ?array $additional_parameters
    ): ?int {
        return null;
    }
}
