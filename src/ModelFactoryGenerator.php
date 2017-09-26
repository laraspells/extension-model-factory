<?php

namespace LaraSpells\Extension\ModelFactory;

use LaraSpells\Generator\Generators\BaseGenerator;
use LaraSpells\Generator\Generators\CodeGenerator;
use LaraSpells\Generator\Generators\Concerns\TableUtils;
use LaraSpells\Generator\Schema\Field;
use LaraSpells\Generator\Schema\Table;
use LaraSpells\Generator\Stub;

class ModelFactoryGenerator extends BaseGenerator
{
    use TableUtils;

    protected $tableSchema;

    protected $personsTable = [
        'person',
        'user',
        'employee',
        'karyawan',
        'teacher',
        'guru',
        'student',
        'siswa',
    ];

    protected $organizationsTable = [
        'brand',
        'company',
        'perusahaan',
        'merk',
        'school',
        'sekolah',
        'university',
        'universitas',
    ];

    protected $names  = ['name', 'nama'];
    protected $cities = ['city', 'kota'];
    protected $countries = ['country', 'negara'];
    protected $districts = ['district', 'daerah', 'wilayah', 'lokasi'];
    protected $postalCodes = ['postcode', 'postal_code', 'kode_pos'];

    protected function tableMatch(Table $table, $regex)
    {
        return (bool) preg_match($regex, $table->getName());
    }

    public function __construct(Table $tableSchema)
    {
        $this->tableSchema = $tableSchema;
    }

    public function generateLines()
    {
        $stubContent = file_get_contents(__DIR__ . '/model-factory.stub');
        $stub        = new Stub($stubContent);

        $data['model_class'] = $this->getTableSchema()->getModelClass();
        $data['code'] = $this->getCode();

        return $this->parseLines($stub->render($data));
    }

    protected function getCode()
    {
        $code = new CodeGenerator;
        $fillables = $this->getTableSchema()->getFillableColumns();
        $fieldUploads = $this->getTableSchema()->getInputFileFields();
        $fieldRelations = array_filter($this->getTableSchema()->getFields(), function ($field) {
            return !empty($field->getRelation());
            return (
                is_array($options) 
                AND isset($options['table'])
                AND isset($options['value'])
            );
        });

        // Add relations static vars
        foreach ($fieldRelations as $field) {
            $colName = $field->getColumnName();
            $varName = 'options'.ucfirst(camel_case($colName));
            $code->addCode("static \${$varName};");
        }

        if ($fieldRelations) $code->nl();

        // Prepare relations options
        foreach ($fieldRelations as $field) {
            $colName = $field->getColumnName();
            $varName = 'options'.ucfirst(camel_case($colName));
            $relation = $field->getRelation();
            $refTable = $relation['table'];
            $refColumn = $relation['key_to'];
            $relatedTable = $this->getTableSchema()->getRootSchema()->getTable($refTable);
            $relatedModel = $relatedTable->getModelClass();
            $code->addCode("
                if (!is_array(\${$varName})) {
                    \${$varName} = {$relatedModel}::pluck('{$refColumn}')->toArray();
                    if (empty(\${$varName})) throw new Exception('Cannot generate factory. {$relatedModel} records is empty.');
                }
            ");
        }

        if ($fieldRelations) $code->nl();
        
        // Upload path and dir
        foreach ($fieldUploads as $field) {
            $disk = $field->getUploadDisk();
            $path = $field->getUploadPath();
            $varPath = $this->getPathVar($field);
            $varDir = $this->getDirVar($field);
            $code->addCode("\${$varPath} = Storage::disk('{$disk}')->path('');");
            $code->addCode("\${$varDir} = '{$path}';");
            $code->nl();
        }

        // Values Code
        $values = [];
        foreach ($fillables as $colName) {
            $field = $this->getTableSchema()->getField($colName);
            $values[$field->getColumnName()] = 'eval("' . $this->getFaker($field) . '")';
        }
        $code->addCode("return ".$this->phpify($values, true).";");

        return $code->generateCode();
    }

    protected function getFaker(Field $field)
    {
        list($type, $params) = $this->parseTypeAndParams($field);
        $tableName = $this->getTableSchema()->getName();
        $colName = $field->getColumnName();

        $fakers = [
            // relation
            function () use ($tableName, $colName, $type, $params, $field) {
                if ($field->getRelation()) {
                    $colName = $field->getColumnName();
                    $varName = 'options'.ucfirst(camel_case($colName));
                    return "\$faker->randomElement(\${$varName})";
                }
            },
            // enum
            function () use ($tableName, $colName, $type, $params, $field) {
                if ($this->typeIs($type, 'ENUM')) {
                    $options = array_map(function ($opt) { return $opt['value']; }, $field->get('input.options') ?: []);
                    $options = $this->phpify(array_unique(array_merge($params, $options)));
                    return "\$faker->randomElement({$options})";
                }
            },
            // email
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "email")) {
                    return "\$faker->unique()->safeEmail";
                }
            },
            // person name
            function () use ($tableName, $colName, $type, $field) {
                $isPersonTable = $this->like($tableName, $this->personsTable);
                if ($isPersonTable && $this->like($colName, $this->names)) {
                    return "\$faker->name";
                }
            },
            // username
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(username)")) {
                    return "\$faker->userName";
                }
            },
            // company name
            function () use ($tableName, $colName, $type, $field) {
                $isOrganizationTable = $this->like($tableName, $this->organizationsTable);
                if ($isOrganizationTable && !$this->isFieldId($field) && $this->like($colName, $this->names)) {
                    return "\$faker->company";
                }
            },
            // phone number
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(phone|telepon)") && !$this->isFieldId($field)) {
                    return "\$faker->phoneNumber";
                }
            },
            // image
            function () use ($tableName, $colName, $type, $field) {
                if ($field->isInputFile()) {
                    $width = 320;
                    $height = 320;
                    $keyword = 'cats';
                    $varDir = $this->getDirVar($field);
                    $varPath = $this->getPathVar($field);
                    return "\${$varDir} . '/' . \$faker->image(\${$varPath} . \${$varDir}, {$width}, {$height}, '{$keyword}', false)";
                }
            },
            // fax number
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(fax)") && !$this->isFieldId($field)) {
                    return "\$faker->faxNumber";
                }
            },
            // country
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, $this->countries) && !$this->isFieldId($field)) {
                    return "\$faker->country";
                }
            },
            // city
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, $this->cities) && !$this->isFieldId($field)) {
                    return "\$faker->cityName";
                }
            },
            // district
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, $this->districts) && !$this->isFieldId($field)) {
                    return "\$faker->state";
                }
            },
            // address
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(address|alamat)") && !$this->isFieldId($field)) {
                    return "\$faker->address";
                }
            },
            // postal code
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, $this->postalCodes) && !$this->isFieldId($field)) {
                    return "\$faker->postcode";
                }
            },
            // latitude
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(latitude|^lat$)")) {
                    return "\$faker->latitude";
                }
            },
            // longitude
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(longitude|^lng$)")) {
                    return "\$faker->longitude";
                }
            },
            // ip address
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(^ip$|ip_address)")) {
                    return "\$faker->ipv4";
                }
            },
            // domain
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(domain)")) {
                    return "\$faker->domainName";
                }
            },
            // password
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(password)")) {
                    return "bcrypt('password')";
                }
            },
            // url
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(url)")) {
                    return "\$faker->url";
                }
            },
            // title
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(title)")) {
                    $length = $field->getLength();
                    return "ucwords(\$faker->text({$length}))";
                }
            },
            // token
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(token|^kd_|^kode_|_code$)")) {
                    $length = $field->getLength();
                    return "str_random({$length})";
                }
            },
            // content
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(content|body)") && $this->typeIs($type, ['TEXT', 'LONGTEXT', 'MEDIUMTEXT', 'SMALLTEXT'])) {
                    return "\$faker->paragraphs(rand(5, 10), true)";
                }
            },
            // date
            function () use ($tableName, $colName, $type, $field) {
                if ($this->like($colName, "(date|tanggal|^tgl)")) {
                    if ($this->typeIs($type, 'DATE')) {
                        return "\$faker->dateTimeBetween('-1 years', 'now')->format('Y-m-d')";
                    } else if ($this->typeIs($type, 'DATETIME')) {
                        return "\$faker->dateTimeBetween('-1 years', 'now')->format('Y-m-d H:i:s')";
                    }
                }
            },
            // numbers
            function () use ($tableName, $colName, $type, $field) {
                if ($this->typeIs($type, ['INT', 'INTEGER', 'SMALLINT', 'TINYINT', 'BIGINT', 'MEDIUMINT'])) {
                    return "\$faker->randomNumber()";
                }
            },
            // float
            function () use ($tableName, $colName, $type, $field) {
                if ($this->typeIs($type, ['FLOAT'])) {
                    return "\$faker->randomFloat()";
                }
            },
            // default
            function () use ($tableName, $colName, $type, $field) {
                $length = $field->getLength();
                if ($length) {
                    return "\$faker->text({$length})";
                } else {
                    return "\$faker->text()";
                }
            },
        ];

        foreach ($fakers as $faker) {
            $result = $faker();
            if ($result) {
                return $result;
            }
        }

        return "\$faker->text()";
    }

    protected function getDirVar(Field $field)
    {
        return camel_case($field->getColumnName()).'Dir';
    }

    protected function getPathVar(Field $field)
    {
        return camel_case($field->getColumnName()).'Path';
    }

    protected function typeIs(string $type, $types)
    {
        $types = (array) $types;
        return in_array(strtoupper($type), $types);
    }

    protected function like(string $str, $likes)
    {
        if (is_array($likes)) {
            $likes = implode('|', $likes);
        }
        return (bool) preg_match("/({$likes})/", $str);
    }

    protected function getTableSchema()
    {
        return $this->tableSchema;
    }

    protected function isFieldId(Field $field)
    {
        return $this->like($field->getColumnName(), "id");
    }

    protected function parseTypeAndParams(Field $field)
    {
        $exp = explode(':', $field->getType(), 2);
        $type = $exp[0];
        $params = isset($exp[1])? array_map(function($param) {
            return trim($param);
        }, explode(',', $exp[1])) : [];

        return [$type, $params];
    }

}
