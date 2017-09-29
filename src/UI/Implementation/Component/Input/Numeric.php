<?php

/* Copyright (c) 2017 Timon Amstutz <timon.amstutz@ilub.unibe.ch> Extended GPL, see
docs/LICENSE */

namespace ILIAS\UI\Implementation\Component\Input;

use ILIAS\Data\Factory as DataFactory;
use ILIAS\UI\Component as C;
use ILIAS\Validation\Factory as ValidationFactory;
use ILIAS\Transformation\Factory as TransformationFactory;

/**
 * This implements the numeric input.
 */
class Numeric extends Input implements C\Input\Numeric {

	/**
	 * Numeric constructor.
	 * @param DataFactory $data_factory
	 * @param $label
	 * @param $byline
	 */
	public function __construct(DataFactory $data_factory, ValidationFactory $validation_factory, TransformationFactory $transformation_factory, $label, $byline) {

		parent::__construct($data_factory, $validation_factory, $transformation_factory, $label, $byline);

		//TODO: Is there a better way to do this? Note, that "withConstraint" is not
		// usable here (clone).
		$this->setAdditionalConstraint($this->validation_factory->isNumeric());
	}

	/**
	 * @inheritdoc
	 */
	protected function isClientSideValueOk($value) {
		return is_string($value);
	}


	/**
	 * @inheritdoc
	 */
	protected function getConstraintForRequirement() {
		throw new \LogicException("NYI: What could 'required' mean here?");
	}
}
