<?php


#[AllowDynamicProperties]
class ObjectProxy
{
    /** @var object O objeto encapsulado */
    protected object $__object;

    /**
     * Construtor para encapsular um objeto.
     *
     * @param object $object O objeto que será encapsulado.
     */
    public function __construct(object $object)
    {
        if (!is_object($object)) {
            throw new InvalidArgumentException('O parâmetro deve ser um objeto.');
        }
        $this->__object = $object;
    }

    /**
     * Obtém uma propriedade do objeto encapsulado.
     *
     * @param string $name Nome da propriedade.
     * @return mixed
     */
    public function get(string $name)
    {
        if (property_exists($this->__object, $name)) {
         //  //\Plugin\Utils\cli::pcl($name." foi chamado", 'blue');
         //  if (!isset($GLOBALS[$name])) \plugins\Utils\cache::set($name, 1);
         //  else \plugins\Utils\cache::increment($name);

            if (\plugins\Utils\cache::get($name) > 1) {
               // \Plugin\Utils\cli::pcl("$name foi invocado " . \plugins\Utils\cache::get($name) . " vezes", 'blue');
                $debugTrace = debug_backtrace();
                //\Plugin\Utils\cli::pcl("Trace: " . $debugTrace[0]['file'] . " na linha " . $debugTrace[0]['line'], 'blue');
            }


            return $this->__object->{$name};
        }
        throw new RuntimeException("A propriedade '{$name}' não existe no objeto.");
    }

    /**
     * Define uma propriedade no objeto encapsulado.
     *
     * @param string $name Nome da propriedade.
     * @param mixed $value Valor a ser atribuído.
     */
    public function set(string $name, mixed $value): void
    {
        $this->__object->{$name} = $value;
    }

    /**
     * Verifica se uma propriedade está definida.
     *
     * @param string $name Nome da propriedade.
     * @return bool
     */
    public function isset(mixed $name): bool
    {
        return isset($this->__object->{$name});
    }

    /**
     * Remove uma propriedade do objeto encapsulado.
     *
     * @param string $name Nome da propriedade.
     */
    public function unset(string $name): void
    {
        if (property_exists($this->__object, $name)) {
            unset($this->__object->{$name});
            $callId = $name;
            foreach ($GLOBALS['object'] as $id => $object) {
                if ($id == $callId) unset($GLOBALS['object']->$id);
            }

            try {
                /** @var ArrayObjectDynamic $objects */
                $objects = $GLOBALS['object'];
            } catch (Exception $e) {
                \Plugin\Utils\cli::pcl("Erro ao acessar o objeto global 'object': " . $e->getMessage(), 'red');
                \Plugin\Utils\cli::pcl("Certifique-se de que o objeto 'object' está definido corretamente.", 'yellow');
                return;
            }

            try {
                $object = $objects;
                unset($object->$callId);
            } catch (Exception $e) {
                \Plugin\Utils\cli::pcl("Erro ao acessar o objeto global 'object': " . $e->getMessage(), 'red');
                \Plugin\Utils\cli::pcl("Certifique-se de que o objeto 'object' está definido corretamente.", 'yellow');
                return;
            }
        } else {
            return;
        }
    }

    /**
     * Chama um método do objeto encapsulado.
     *
     * @param string $name Nome do método.
     * @param array $arguments Argumentos a serem passados.
     * @return mixed
     */
    public function call(string $name, array $arguments): mixed
    {
        if (method_exists($this->__object, $name)) {
            return $this->__object->{$name}(...$arguments);
        }
        throw new RuntimeException("O método '{$name}' não existe no objeto.");
    }

    /**
     * Invoca o objeto encapsulado se ele for invocável.
     *
     * @param mixed ...$arguments Argumentos a serem passados.
     * @return mixed
     */
    public function invoke(...$arguments): mixed
    {
        if (is_callable($this->__object)) {
            return ($this->__object)(...$arguments);
        }
        throw new RuntimeException('O objeto encapsulado não é invocável.');
    }
}
