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
    protected \ilBiblFactoryFacade $facade;

    public function __construct(protected \ILIAS\UI\Factory $ui_factory, protected \ILIAS\UI\Renderer $ui_renderer,ilBiblFactoryFacade $facade)
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
            yield $row_builder->buildDataRow($row_id, $record);
        }
    }

    protected function getRecords(Order $order): array
    {
        $records = [
            ['id' => 0, 'field' => 'test1','filter_type' => 'superuser'],
            ['id' => 1, 'field' => 'test2','filter_type' => 'student1'],
            ['id' => 2, 'field' => 'test3','filter_type' => 'student2'],
            ['id' => 3, 'field' => 'test4','filter_type' => 'student3']
        ];

        //$records = $this->facade->filterFactory()->getAllForObjectId($this->facade->iliasObjId());

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
