<?php
/**
 * This file is part of the Ray package.
 *
 * @package Ray.Di
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace Ray\Di;

use Aura\Di\ContainerInterface;
use Aura\Di\Exception\ContainerLocked;
use Aura\Di\Lazy;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\Cache;
use LogicException;
use Ray\Aop\Bind;
use Ray\Aop\BindInterface;
use Ray\Aop\Compiler;
use Ray\Aop\CompilerInterface;
use Ray\Di\Exception;
use Ray\Di\Exception\Binding;
use Ray\Di\Exception\NotBound;
use Ray\Di\Exception\OptionalInjectionNotBound;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SplObjectStorage;
use Ray\Di\Di\Inject;

/**
 * Dependency Injector
 *
 * @package Ray.Di
 */
class Injector implements InjectorInterface
{
    /**
     * Inject annotation with optional=false
     *
     * @var bool
     */
    const OPTIONAL_BINDING_NOT_BOUND = false;

    /**
     * Config
     *
     * @var Config
     */
    protected $config;

    /**
     * Container
     *
     * @var \Ray\Di\Container
     */
    protected $container;

    /**
     * Binding module
     *
     * @var AbstractModule
     */
    protected $module;

    /**
     * Pre-destroy objects
     *
     * @var SplObjectStorage
     */
    private $preDestroyObjects;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private $log;

    /**
     * Current working class for exception message
     *
     * @var string
     */
    private $class;

    /**
     * Cache adapter
     *
     * @var Cache
     */
    private $cache;

    /**
     * Compiler(Aspect Weaver)
     *
     * @var \Ray\Aop\CompilerInterface
     */
    private $compiler;

    /**
     * @param ContainerInterface $container The class to instantiate.
     * @param AbstractModule     $module    Binding configuration module
     * @param BindInterface      $bind      Aspect binder
     * @param CompilerInterface  $compiler  Aspect weaver
     *
     * @Inject
     */
    public function __construct(
        ContainerInterface $container,
        AbstractModule $module = null,
        BindInterface $bind = null,
        CompilerInterface $compiler = null
    ) {
        $this->container = $container;
        $this->module = $module ? : new EmptyModule;
        $this->bind = $bind ? : new Bind;
        $this->compiler = $compiler ?: new Compiler;
        $this->preDestroyObjects = new SplObjectStorage;
        $this->config = $container->getForge()->getConfig();
        $this->module->activate($this);

        AnnotationRegistry::registerAutoloadNamespace('Ray\Di\Di', __DIR__ . '/Di');
    }

    /**
     * {@inheritdoc
     */
    public static function create(array $modules = [], Cache $cache = null)
    {
        $annotationReader = ($cache instanceof Cache) ? new CachedReader(new AnnotationReader, $cache) : new AnnotationReader;
        $injector = new self(new Container(new Forge(new Config(new Annotation(new Definition, $annotationReader)))));

        if (count($modules) > 0) {
            $module = array_shift($modules);
            foreach ($modules as $extraModule) {
                /* @var $module AbstractModule */
                $module->install($extraModule);
            }
            $injector->setModule($module);
        }

        return $injector;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated
     */
    public function setCache(Cache $cache)
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * {@inheritdoc}
     */
    public function setModule(AbstractModule $module, $activate = true)
    {
        if ($this->container->isLocked()) {
            throw new ContainerLocked;
        }
        if ($activate === true) {
            $module->activate($this);
        }
        $this->module = $module;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->log = $logger;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getLogger()
    {
        return $this->log;
    }

    /**
     * {@inheritdoc}
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @return SplObjectStorage
     */
    public function getPreDestroyObjects()
    {
        return $this->preDestroyObjects;
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->notifyPreShutdown();
    }

    /**
     * Notify pre-destroy
     *
     * @return void
     */
    private function notifyPreShutdown()
    {
        $this->preDestroyObjects->rewind();
        while ($this->preDestroyObjects->valid()) {
            $object = $this->preDestroyObjects->current();
            $method = $this->preDestroyObjects->getInfo();
            $object->$method();
            $this->preDestroyObjects->next();
        }
    }

    /**
     * Clone
     */
    public function __clone()
    {
        $this->container = clone $this->container;
    }

    /**
     * {@inheritdoc}
     */
    public function getInstance($class, array $params = null)
    {
        $bound = $this->getBound($class);

        // return singleton bound object if exists
        if (is_object($bound)) {
            return $bound;
        }

        // return cached object
        list($cacheKey, $cachedObject) = $this->getCachedObject(debug_backtrace(), $class);
        if ($cachedObject) {
            return $cachedObject;
        }

        // get bound config
        list($class, $isSingleton, $interfaceClass, $config, $setter, $definition) = $bound;

        // override construction parameter
        $params = is_null($params) ? $config : array_merge($config, (array)$params);

        // lazy-load params as needed
        foreach ($params as $key => $val) {
            if ($params[$key] instanceof Lazy) {
                $params[$key] = $params[$key]();
            }
        }

        // be all parameters ready
        $this->constructorInject($class, $params, $this->module);

        // is instantiable ?
        if (!(new \ReflectionClass($class))->isInstantiable()) {
            throw new Exception\NotInstantiable($class);
        }

        // weave aspect
        $module = $this->module;
        $bind = $module($class, new $this->bind);
        /* @var $bind \Ray\Aop\Bind */

        $object = $bind->hasBinding() ?
            $this->compiler->newInstance($class, $params, $bind) : $this->newInstance($class, $params) ;

        // call setter methods
        $this->setterMethod($setter, $object);

        // log inject info
        if ($this->log) {
            $this->log->log($class, $params, $setter, $object, $bind);
        }

        // set life cycle
        if ($definition) {
            $this->setLifeCycle($object, $definition);
        }

        // set singleton object
        if ($isSingleton) {
            $this->container->set($interfaceClass, $object);
        }

        if ($cacheKey) {
            /** @noinspection PhpUndefinedVariableInspection */
            $this->cache->save($cacheKey, $object);
        }

        return $object;
    }

    /**
     * Return new instance
     *
     * @param string $class
     * @param array  $params
     *
     * @return object
     */
    private function newInstance($class, array $params)
    {
        return call_user_func_array(
            [$this->config->getReflect($class), 'newInstance'],
            $params
        );
    }

    /**
     * Return bound object or inject info
     *
     * @param $class
     *
     * @return array|object
     * @throws Exception\NotReadable
     */
    private function getBound($class)
    {
        $class = $this->removeLeadingBackSlash($class);

        // is interface ?
        try {
            $refClass = new ReflectionClass($class);
            $isInterface = $refClass->isInterface();
            $isInstantiable = $refClass->isInstantiable();
        } catch (ReflectionException $e) {
            throw new Exception\NotReadable($class);
        }

        list($config, $setter, $definition) = $this->config->fetch($class);
        $interfaceClass = $isSingleton = false;
        if ($isInterface) {
            $bound = $this->getBoundClass($this->module->bindings, $definition, $class);
            if (is_object($bound)) {
                return $bound;
            }
            list($class, $isSingleton, $interfaceClass) = $bound;
            list($config, $setter, $definition) = $this->config->fetch($class);
        } elseif ($isInstantiable) {
            try {
                $bound = $this->getBoundClass($this->module->bindings, $definition, $class);
                if (is_object($bound)) {
                    return $bound;
                }
            } catch (NotBound $e) {

            }
        }
        $hasDirectBinding = isset($this->module->bindings[$class]);
        /** @var $definition Definition */
        if ($definition->hasDefinition() || $hasDirectBinding) {
            list($config, $setter) = $this->bindModule($setter, $definition);
        }

        return [$class, $isSingleton, $interfaceClass, $config, $setter, $definition];
    }

    /**
     * Remove leading back slash
     *
     * @param string $class
     *
     * @return string
     */
    private function removeLeadingBackSlash($class)
    {
        $isLeadingBackSlash = (strlen($class) > 0 && $class[0] === '\\');
        if ($isLeadingBackSlash === true) {
            $class = substr($class, 1);
        }

        return $class;
    }

    /**
     * Get bound class or object
     *
     * @param        $bindings
     * @param mixed  $definition
     * @param string $class
     *
     * @return array|object
     * @throws Exception\NotBound
     */
    private function getBoundClass($bindings, $definition, $class)
    {
        if (!isset($bindings[$class]) || !isset($bindings[$class]['*']['to'][0])) {
            $msg = "Interface \"$class\" is not bound.";
            throw new Exception\NotBound($msg);
        }
        $toType = $bindings[$class]['*']['to'][0];
        $isToProviderBinding = ($toType === AbstractModule::TO_PROVIDER);
        if ($isToProviderBinding) {
            $provider = $bindings[$class]['*']['to'][1];
            $in = isset($bindings[$class]['*']['in']) ? $bindings[$class]['*']['in'] : null;
            if ($in !== Scope::SINGLETON) {
                return $this->getInstance($provider)->get();
            }
            if (!$this->container->has($class)) {
                $object = $this->getInstance($provider)->get();
                $this->container->set($class, $object);

            }

            return $this->container->get($class);
        }

        $inType = isset($bindings[$class]['*'][AbstractModule::IN]) ? $bindings[$class]['*'][AbstractModule::IN] : null;
        $inType = is_array($inType) ? $inType[0] : $inType;
        $isSingleton = $inType === Scope::SINGLETON || $definition['Scope'] == Scope::SINGLETON;
        $interfaceClass = $class;

        if ($isSingleton && $this->container->has($interfaceClass)) {
            $object = $this->container->get($interfaceClass);

            return $object;
        }

        if ($toType === AbstractModule::TO_CLASS) {
            $class = $bindings[$class]['*']['to'][1];
        } elseif ($toType === AbstractModule::TO_INSTANCE) {
            return $bindings[$class]['*']['to'][1];
        }

        return [$class, $isSingleton, $interfaceClass];
    }

    /**
     * Return dependency using modules.
     *
     * @param array      $setter
     * @param Definition $definition
     *
     * @return array <$constructorParams, $setter>
     * @throws Exception\Binding
     * @throws \LogicException
     */
    private function bindModule(array $setter, Definition $definition)
    {
        // @return array [AbstractModule::TO => [$toMethod, $toTarget]]
        $container = $this->container;
        /* @var $forge \Ray\Di\Forge */
        $injector = $this;
        $getInstance = function ($in, $bindingToType, $target) use ($container, $definition, $injector) {
            if ($in === Scope::SINGLETON && $container->has($target)) {
                $instance = $container->get($target);

                return $instance;
            }
            $isToClassBinding = $bindingToType === AbstractModule::TO_CLASS;
            $instance = $isToClassBinding ? $injector->getInstance($target) : $injector->getInstance($target)->get();

            if ($in === Scope::SINGLETON) {
                $container->set($target, $instance);
            }

            return $instance;
        };
        // main
        $setterDefinitions = (isset($definition[Definition::INJECT][Definition::INJECT_SETTER])) ? $definition[Definition::INJECT][Definition::INJECT_SETTER] : null;
        if ($setterDefinitions !== null) {
            $injected = [];
            foreach ($setterDefinitions as $setterDefinition) {
                try {
                    $injected[] = $this->bindMethod($setterDefinition, $definition, $getInstance);
                } catch (OptionalInjectionNotBound $e) {
                }
            }
            $setter = [];
            foreach ($injected as $item) {
                list($setterMethod, $object) = $item;
                $setter[$setterMethod] = $object;
            }
        }
        // constructor injection ?
        $params = isset($setter['__construct']) ? $setter['__construct'] : [];
        $result = [$params, $setter];

        return $result;
    }

    /**
     * Bind method
     *
     * @param array      $setterDefinition
     * @param Definition $definition
     * @param callable   $getInstance
     *
     * @return array
     */
    private function bindMethod(array $setterDefinition, Definition $definition, callable $getInstance)
    {
        list($method, $settings) = each($setterDefinition);

        array_walk($settings, [$this, 'bindOneParameter'], [$definition, $getInstance]);

        return [$method, $settings];
    }

    /**
     * @param array $trace
     * @param       $class
     *
     * @return array|mixed
     */
    private function getCachedObject(array $trace, $class)
    {
        static $loaded = [];

        $isNotRecursive = ($trace[0]['file'] !== __FILE__);
        $isFirstLoadInThisSession = (!in_array($class, $loaded));
        $useCache = ($this->cache instanceof Cache && $isNotRecursive && $isFirstLoadInThisSession);
        $loaded[] = $class;
        // cache read ?
        if ($useCache) {
            $cacheKey = PHP_SAPI . get_class($this->module) . $class;
            $object = $this->cache->fetch($cacheKey);
            if ($object) {
                return [$cacheKey, $object];
            }
            return [$cacheKey, null];
        }

        return [null, null];
    }

    /**
     * Return parameter using TO_CONSTRUCTOR
     *
     * 1) If parameter is provided, return. (check)
     * 2) If parameter is NOT provided and TO_CONSTRUCTOR binding is available, return parameter with it
     * 3) No binding found, throw exception.
     *
     * @param string         $class
     * @param array          &$params
     * @param AbstractModule $module
     *
     * @return void
     * @throws Exception\NotBound
     */
    private function constructorInject($class, array &$params, AbstractModule $module)
    {
        $ref = method_exists($class, '__construct') ? new ReflectionMethod($class, '__construct') : false;
        if ($ref === false) {
            return;
        }
        $parameters = $ref->getParameters();
        foreach ($parameters as $index => $parameter) {
            /* @var $parameter \ReflectionParameter */

            // has binding ?
            $params = array_values($params);
            if (!isset($params[$index])) {
                $hasConstructorBinding = ($module[$class]['*'][AbstractModule::TO][0] === AbstractModule::TO_CONSTRUCTOR);
                if ($hasConstructorBinding) {
                    $params[$index] = $module[$class]['*'][AbstractModule::TO][1][$parameter->name];
                    continue;
                }
                // has constructor default value ?
                if ($parameter->isDefaultValueAvailable() === true) {
                    continue;
                }
                // is typehint class ?
                $classRef = $parameter->getClass();
                if ($classRef && !$classRef->isInterface()) {
                    $params[$index] = $this->getInstance($classRef->getName());
                    continue;
                }
                $msg = is_null($classRef) ? "Valid interface is not found. (array ?)" : "Interface [{$classRef->name}] is not bound.";
                $msg .= " Injection requested at argument #{$index} \${$parameter->name} in {$class} constructor.";
                throw new Exception\NotBound($msg);
            }
        }
    }

    /**
     * @param array $setter
     * @param       $object
     */
    private function setterMethod(array $setter, $object)
    {
        foreach ($setter as $method => $value) {
            // does the specified setter method exist?
            if (! method_exists($object, $method)) {
                continue;
            }
            if (!is_array($value)) {
                // call the setter
                $object->$method($value);
                continue;
            }
            call_user_func_array([$object, $method], $value);
        }
    }

    /**
     * Set object life cycle
     *
     * @param object     $instance
     * @param Definition $definition
     *
     * @return void
     */
    private function setLifeCycle($instance, Definition $definition = null)
    {
        $postConstructMethod = $definition[Definition::POST_CONSTRUCT];
        if ($postConstructMethod) {
            call_user_func(array($instance, $postConstructMethod));
        }
        if (!is_null($definition[Definition::PRE_DESTROY])) {
            $this->preDestroyObjects->attach($instance, $definition[Definition::PRE_DESTROY]);
        }

    }

    /**
     * Lock
     *
     * Lock the Container so that configuration cannot be accessed externally,
     * and no new service definitions can be added.
     *
     * @return void
     */
    public function lock()
    {
        $this->container->lock();
    }

    /**
     * Lazy new
     *
     * Returns a Lazy that creates a new instance. This allows you to replace
     * the following idiom:
     *
     * @param string $class  The type of class of instantiate.
     * @param array  $params Override parameters for the instance.
     *
     * @return Lazy A lazy-load object that creates the new instance.
     */
    public function lazyNew($class, array $params = [])
    {
        return $this->container->lazyNew($class, $params);
    }

    /**
     * Magic get to provide access to the Config::$params and $setter
     * objects.
     *
     * @param string $key The property to retrieve ('params' or 'setter').
     *
     * @return mixed
     */
    public function __get($key)
    {
        return $this->container->__get($key);
    }

    /**
     * Return module information.
     *
     * @return string
     */
    public function __toString()
    {
        $result = (string)($this->module);

        return $result;
    }

    /**
     * Set one parameter with definition, or JIT binding.
     *
     * @param array  &$param
     * @param string $key
     * @param array  $userData
     *
     * @return void
     * @throws Exception\OptionalInjectionNotBound
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private function bindOneParameter(array &$param, $key, array $userData)
    {
        list(, $getInstance) = $userData;
        $annotate = $param[Definition::PARAM_ANNOTATE];
        $typeHint = $param[Definition::PARAM_TYPEHINT];
        $hasTypeHint = isset($this->module[$typeHint]) && isset($this->module[$typeHint][$annotate]) && ($this->module[$typeHint][$annotate] !== []);
        $binding = $hasTypeHint ? $this->module[$typeHint][$annotate] : false;
        if ($binding === false || isset($binding[AbstractModule::TO]) === false) {
            // default value
            if (array_key_exists(Definition::DEFAULT_VAL, $param)) {
                $param = $param[Definition::DEFAULT_VAL];

                return;
            }
            // default binding by @ImplementedBy or @ProviderBy
            $binding = $this->jitBinding($param, $typeHint, $annotate);
            if ($binding === self::OPTIONAL_BINDING_NOT_BOUND) {
                throw new OptionalInjectionNotBound($key);
            }
        }
        list($bindingToType, $target) = $binding[AbstractModule::TO];
        if ($bindingToType === AbstractModule::TO_INSTANCE) {
            $param = $target;

            return;
        } elseif ($bindingToType === AbstractModule::TO_CALLABLE) {
            /* @var $target \Closure */
            $param = $target();

            return;
        }
        if (isset($binding[AbstractModule::IN])) {
            $in = $binding[AbstractModule::IN];
        } elseif (isset($binding[AbstractModule::IN][0])) {
            $in = $binding[AbstractModule::IN][0];
        } else {
            list($param, , $definition) = $this->config->fetch($typeHint);
            $in = isset($definition[Definition::SCOPE]) ? $definition[Definition::SCOPE] : Scope::PROTOTYPE;
        }
        /* @var $getInstance \Closure */
        $param = $getInstance($in, $bindingToType, $target);
    }

    /**
     * JIT binding
     *
     * @param array  $param
     * @param string $typeHint
     * @param string $annotate
     *
     * @return array|bool
     * @throws Exception\NotBound
     */
    private function jitBinding(array $param, $typeHint, $annotate)
    {
        $typeHintBy = $param[Definition::PARAM_TYPEHINT_BY];
        if ($typeHintBy == []) {
            if ($param[Definition::OPTIONAL] === true) {
                return self::OPTIONAL_BINDING_NOT_BOUND;
            }
            $name = $param[Definition::PARAM_NAME];
            $msg = "typehint='{$typeHint}', annotate='{$annotate}' for \${$name} in class '{$this->class}'";
            $e = (new Exception\NotBound($msg))->setModule($this->module);
            throw $e;
        }
        if ($typeHintBy[0] === Definition::PARAM_TYPEHINT_METHOD_IMPLEMETEDBY) {
            return [AbstractModule::TO => [AbstractModule::TO_CLASS, $typeHintBy[1]]];
        }

        return [AbstractModule::TO => [AbstractModule::TO_PROVIDER, $typeHintBy[1]]];
    }
}
