<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Plugin\Mirasai\Rereplacer\Support\ConditionsService;
use Mirasai\Plugin\Mirasai\Rereplacer\Support\RereplacerService;

abstract class AbstractRereplacerTool extends AbstractTool
{
    protected RereplacerService $rereplacer;
    protected ConditionsService $conditions;

    public function __construct()
    {
        parent::__construct();
        $this->rereplacer = new RereplacerService($this->db);
        $this->conditions = new ConditionsService($this->db);
    }
}
