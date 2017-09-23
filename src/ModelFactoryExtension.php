<?php

namespace LaraSpells\Extension\ModelFactory;

use LaraSpells\Generator\Extension;
use LaraSpells\Generator\Schema\Table;

class ModelFactoryExtension extends Extension
{

    public function beforeGenerateEachCrud(Table $table)
    {
        $modelFactoryGenerator = new ModelFactoryGenerator($table);
        $code = $modelFactoryGenerator->generateCode();
        $filePath = 'database/factories/'.($table->getModelClass(false)).'Factory.php';
        $this->generateFile($filePath, $code);
    }

}
