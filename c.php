<?php

function safeExport($v)
{
    return str_replace('array (', '[', str_replace("\n)", "]", var_export($v, true)));
}

function writeStubFile($namespace, $className, $code)
{
    $dir = __DIR__ . '/stubs/' . str_replace('\\', '/', $namespace);
    @mkdir($dir, 0777, true);
    file_put_contents("$dir/{$className}.php", $code);
}


function generateFunctionStubs(string $ext)
{
    if (!extension_loaded($ext)) {
        echo "❌ Extensão $ext não está carregada\n";
        return;
    }

    $internalFuncs = get_extension_funcs($ext) ?: [];
    $userFuncs = get_defined_functions()['user'];


    foreach ($userFuncs as $fn) {

        try {
            $rfm = new ReflectionFunction($fn);


            $internalFuncs[] = $fn;

        } catch (ReflectionException) {
            continue;
        }
    }

    $groupedByNamespace = [];
    foreach ($internalFuncs as $function) {
        $parts = explode('\\', $function);
        $funcName = array_pop($parts);
        $namespace = implode('\\', $parts);

        $groupedByNamespace[$namespace][] = [$function, $funcName];
    }

    foreach ($groupedByNamespace as $namespace => $functionList) {
        $code = "<?php\n\ndeclare(strict_types=1);\n\n";
        if ($namespace) {
            $code .= "namespace $namespace {\n\n";
        }

        foreach ($functionList as [$fullName, $funcName]) {
            try {
                $rfm = new ReflectionFunction($fullName);
            } catch (ReflectionException) {
                continue;
            }

            $params = [];
            foreach ($rfm->getParameters() as $p) {
                $type = 'mixed';
                $t = $p->getType();
                if ($t instanceof ReflectionNamedType) {
                    $type = $t->getName();
                } elseif ($t instanceof ReflectionUnionType) {
                    $type = implode('|', array_map(fn($t) => $t->getName(), $t->getTypes()));
                }

                $s = ($type !== 'mixed' ? "$type " : '');
                if ($p->isPassedByReference()) $s .= '&';
                $s .= '$' . $p->getName();

                if ($p->isOptional()) {
                    $default = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
                    $s .= ' = ' . safeExport($default);
                }

                $params[] = $s;
            }

            $return = 'mixed';
            $rType = $rfm->getReturnType();
            if ($rType instanceof ReflectionNamedType) {
                $return = $rType->getName();
            }

            $code .= ($rfm->getDocComment() ?: '') . "\n";
            $code .= "    function $funcName(" . implode(', ', $params) . ")";
            if ($return !== 'mixed') $code .= ": $return";
            $code .= " {\n        ";
            $code .= match ($return) {
                'string' => 'return "";',
                'int' => 'return 0;',
                'float' => 'return 0.0;',
                'bool' => 'return false;',
                'array' => 'return [];',
                'void' => 'return;',
                default => '// stub',
            };
            $code .= "\n    }\n\n";
        }

        if ($namespace) {
            $code .= "}\n";
        }

        $dir = $ext . ($namespace ? '/' . str_replace('\\', '/', $namespace) : '');
        writeStubFile($dir, 'functions', $code);
    }
}


function generateExtensionConstants(string $ext)
{
    $all = get_defined_constants(true);
    if (!isset($all[$ext])) {
        echo "ℹ️ Nenhuma constante encontrada para extensão $ext\n";
        return;
    }

    $constants = $all[$ext];
    $code = "<?php\n\ndeclare(strict_types=1);\n\n";

    foreach ($constants as $name => $value) {
        // Skip arrays or non-scalar values (só por segurança)
        if (is_array($value) || is_object($value)) continue;
        $code .= "const $name = " . safeExport($value) . ";\n";
    }

    $dir = __DIR__ . "/stubs/$ext";
    @mkdir($dir, 0777, true);
    file_put_contents("$dir/constants.php", $code);
}

function generateClassStubs(array $allowFilters)
{
    foreach (get_declared_classes() as $className) {
        if (str_starts_with($className, '__')) continue;

        $nsParts = explode('\\', $className);
        $classShort = array_pop($nsParts);
        $namespace = implode('\\', $nsParts);

        $found = false;
        foreach ($allowFilters as $f) {
            if (str_contains(strtolower($className), strtolower($f))) {
                $found = true;
                break;
            }
        }
        if (!$found) continue;

        try {
            $rc = new ReflectionClass($className);
        } catch (ReflectionException) {
            continue;
        }


        //$code = "<?php\n\nnamespace $namespace;\n\ndeclare(strict_types=1);\n\n";
        if (!empty($namespace)) {
            $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n";
        } else {
            $code = "<?php\n\ndeclare(strict_types=1);\n\n";
        }


        $code .= ($rc->getDocComment() ?: '') . "\n";
        $code .= "class $classShort {\n";

        foreach ($rc->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $const) {
            $constName = $const->getName();
            $constValue = $const->getValue();
            $code .= "    public const $constName = " . safeExport($constValue) . ";\n";
        }


        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $className) continue;

            $code .= "\n    " . ($m->getDocComment() ?: '') . "\n";

            $static = $m->isStatic() ? 'static ' : '';
            $code .= "    public {$static}function {$m->getName()}(";

            $params = [];
            foreach ($m->getParameters() as $p) {
                $type = 'mixed';
                $t = $p->getType();
                if ($t instanceof ReflectionNamedType) $type = $t->getName();
                elseif ($t instanceof ReflectionUnionType) {
                    $type = implode('|', array_map(fn($t) => $t->getName(), $t->getTypes()));
                }

                $s = ($type !== 'mixed' ? "$type " : '');
                if ($p->isPassedByReference()) $s .= '&';
                $s .= '$' . $p->getName();
                if ($p->isOptional()) {
                    $default = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
                    $s .= ' = ' . safeExport($default);
                }

                $params[] = $s;
            }

            $code .= implode(', ', $params) . ')';

            $return = 'mixed';
            $rType = $m->getReturnType();
            if ($rType instanceof ReflectionNamedType && $rType->getName() !== 'void') {
                $return = $rType->getName();
                if ($return === 'bool') $return = 'mixed';

                $code .= ": $return";
            }

            $code .= " {\n        ";
            $code .= match ($return) {
                'string' => 'return "";',
                'int' => 'return 0;',
                'float' => 'return 0.0;',
                'bool' => 'return false;',
                'array' => 'return [];',
                'void' => 'return;',
                default => '// stub',
            };
            $code .= "\n    }\n";
        }


        $code .= "}\n";

        writeStubFile($namespace, $classShort, $code);
    }
}


// 🔧 Filtrar classes permitidas
generateClassStubs(['bcg729']);
echo "✅ Stubs gerados com namespaces e estrutura de pasta: ./stubs\n";
