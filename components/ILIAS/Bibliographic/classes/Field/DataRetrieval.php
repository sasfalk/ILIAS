<?php

namespace ILIAS\Bibliographic\Field;

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table as I;

/**
 * Class DataRetrieval
 *
 */
class DataRetrieval implements I\DataRetrieval
{
    private \ilLanguage $lng;
    private \ilLanguage $lng;

    public function __construct(
        protected \ilBiblAdminFactoryFacadeInterface $facade
    ) {
        global $DIC;
        $this->lng = $DIC['lng'];
        $this->ctrl = $DIC['ilCtrl'];
    }

    public function getRows(
        I\DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): \Generator
    {
        $records = $this->getRecords($order);
        foreach ($records as $idx => $record) {
            $row_id = (string)$record['id'];
            $field = $this->facade->fieldFactory()->findById($record['id']);
            $record['data_type'] = $this->facade->translationFactory()->translate($field);
            $record['is_standard_field'] = $field->isStandardField() ? $this->lng->txt('standard') : $this->lng->txt('custom');
            yield $row_builder->buildDataRow($row_id, $record);
        }
    }

    protected function getRecords(Order $order): array
    {
        $records = $this->facade->fieldFactory()->filterAllFieldsForTypeAsArray($this->facade->type());
        [$order_field, $order_direction] = $order->join([], fn($ret, $key, $value) => [$key, $value]);
        usort($records, fn($a, $b) => $a[$order_field] <=> $b[$order_field]);
        if ($order_direction === 'DESC') {
            $records = array_reverse($records);
        }
        return $records;
    }

    public function getTotalRowCount(
        ?array $filter_data,
        ?array $additional_parameters
    ): ?int
    {
        return count($this->facade->fieldFactory()->getAvailableFieldsForObjId($this->facade->iliasObjId()));
    }
}
