<?php
namespace Bullet;

use Pimple\Container;

class App extends Container
{
    protected $rootCallbacks;
    protected $currentCallbacks;
    protected $exceptionHandler;

    public function __construct()
    {
        $this->rootCallbacks = [];
        $this->currentCallbacks = &$this->rootCallbacks;
    }

    protected function executeCallback(\Closure $c, array $params = [])
    {
        $c = \Closure::bind($c, $this);
        $response = call_user_func_array($c, $params);

        if ($response === null || $response instanceOf Response) {
            return $response;
        }

        if (is_string($response)) {
            return new Response($response, 200);
        }

        if (is_int($response)) {
            return new Response(null, $response);
        }
    }

    /**
     * Run app with given Request
     *
     * run() ALWAYS return a Response. Returning any other type or
     * throwing an exception is a bug.
     * 
     * Internally run() calls run_() which MIGHT throw exceptions. These
     * exceptions are caught, and handled by respondToE(Exception $e).
     * 
     * @param \Bullet\Request \Bullet\Request object
     * @return \Bullet\Response
     */
    public function run(Request $request)
    {
        try {
            return $this->run_($request);
        } catch (\Exception $e) {
            return $this->respondToE($e);
        }
    }

    /**
     * Run app with given Request
     *
     * run_() either returns a Response, or throws an exception.
     * 
     * It may be called manually from url handlers, or even in
     * index.php when the default exception handling method (respondToE)
     * is not suitable.
     * 
     * The suggested method of calling the Bullet app from itself IS to
     * call run_() manually, since any exception thrown will
     * short-circuit the application and will end up being caught by
     * respondToE() in run() or by a user-defined try-catch in
     * index.php.
     * 
     * The preferred method for custom exception-responses is NOT to
     * call run_() manually, but to call run(), and overwrite the
     * Response's content based on it's status().
     * 
     * Internally run() calls run_() which MIGHT throw exceptions. These
     * exceptions are caught, and handled by respondToE(Exception $e).
     * 
     * @param \Bullet\Request \Bullet\Request object
     * @return \Bullet\Response
     */
    public function run_(Request $request)
    {
        // Save the app's URL parser state (e.g. the current callback map)
        $currentCallbacks = $this->currentCallbacks;
        $this->currentCallbacks = $this->rootCallbacks;

        $response = null;
        try {
            // Remove empty path elements
            $uri = $request->path();
            $parts = [''];
            // with formats a path is no longer a list, but a tree
            // instead with branches begin alternative choices
            // e.g. /foo/bar.js can bee ["foo","bar.js"] path with no
            // format, or ["foo","bar"] with "js" as the format
            foreach (explode('/', $uri) as $part) {
                if ($part != '') {
                    $parts[] = $part;
                }
            }

            // TODO: detect extension

            // Walk through the URI and execute path callbacks
            $cp = count($parts);
            $i = 0;
            $check_format = false;
            foreach ($parts as $part) {
                ++$i;
                // Try to find a callback array for the current URI part
                if (array_key_exists('path', $this->currentCallbacks) && array_key_exists($part, $this->currentCallbacks['path'])) {
                    // Let $c be the callback that has to be run now.
                    $c = $this->currentCallbacks['path'][$part];

                    $this->currentCallbacks = [];
                    $response = $this->executeCallback($c, [$request]);
                    if ($response instanceOf Response) {
                        return $response;
                    }
                }
                // Try to find a param match
                elseif (array_key_exists('param', $this->currentCallbacks)) {
                    // Let $c be the callback that has to be run now.
                    // This needs a linear search trhough the param filters
                    $c = null;
                    foreach ($this->currentCallbacks['param'] as $filterCallbackTuple) {
                        if ($filterCallbackTuple[0]($part)) {
                            $c = $filterCallbackTuple[1];
                            break;
                        }
                    }
                    if ($c instanceOf \Closure) {
                        $this->currentCallbacks = [];
                        $response = $this->executeCallback($c, [$request, $part]);
                        if ($response instanceOf Response) {
                            return $response;
                        }
                    } else {
                        if ($i !== $cp) {
                            return new Response(null, 404); // The last $part might match a format
                        } else {
                            $check_format = true;
                        }
                    }
                } else {
                    if ($i !== $cp) {
                        return new Response(null, 404); // The last $part might match a format
                    } else {
                        $check_format = true;
                    }
                }
            }

            if ($check_format) {
                // TODO: needs a catch-all format
                //return new Response("Yeehaw");
                $_ = explode('.', $part);
                if (count($_) > 1) {
                    $format_part = $_[0];
                    $format_ext = $_[1];
                } else {
                    return new Response(null, 404); // This is not an URL with an extension
                }
            }

            $method = $request->method();

            // The URI has been processed. Call the appropriate method callback
            if (!array_key_exists($method, $this->currentCallbacks)) {
                // Nope, we can't serve this URI, 405 Not Allowed
                return new Response(null, 405, ['Allow' => implode(',', array_keys($this->currentCallbacks))]);
            }

            // There indeed is a method callback, so let's call it!
            $response = $this->executeCallback($this->currentCallbacks[$method], [$request]);

            // If there's a response, we can return it
            if ($response instanceOf Response) {
                return $response;
            }

            // TODO: formats?
            //return new Response(406); // Not acceptable format

            return new Response(null, 501); // Got no error, but got no response either. This is "Not Implemented".
        } finally {
            $this->currentCallbacks = &$currentCallbacks;
        }
    }

    /**
     * Creates a Response from any exception
     * 
     * If the exception is an instance of \Bullet\Response\Exception,
     * then the exception code is used as the status, and the message
     * (if not null) is used as the content.
     * 
     * The response will contain the exception either way.
     */
    public function respondToE(\Exception $e)
    {
        if ($e instanceOf Response\Exception) {
            return (new \Bullet\Response($e->getMessage(), $e->getCode()))->exception($e);
        } else {
            return (new \Bullet\Response(null, 500))->exception($e);
        }
    }

    public function resource(string $part, \Closure $callback)
    {
        $this->currentCallbacks['path'][$part] = $callback;
    }

    public function path($part, \Closure $callback)
    {
        $this->currentCallbacks['path'][$part] = $callback;
    }

    /**
     * Param match has lower priority than path match
     * 
     * e.g. if a path section matches, then the search concludes
     * the current segment and params won't even be searched for a
     * match.
     */
    public function param(\Closure $filter, \Closure $callback)
    {
        $this->currentCallbacks['param'][] = [$filter, $callback];
    }

    /**
     * Handle HTTP content type as output format
     *
     * @param string $format HTTP content type format to handle for
     * @param \Closure $callback Closure to execute to handle specified format
     */
    public function format($format, \Closure $callback)
    {
        $this->currentCallbacks['format'][$format] = $callback;
        return $this;
    }

    public function get(\Closure $callback)
    {
        $this->currentCallbacks['GET'] = $callback;
    }

    public function head(\Closure $callback)
    {
        $this->currentCallbacks['HEAD'] = $callback;
    }

    public function post(\Closure $callback)
    {
        $this->currentCallbacks['POST'] = $callback;
    }

    public function put(\Closure $callback)
    {
        $this->currentCallbacks['PUT'] = $callback;
    }

    public function delete(\Closure $callback)
    {
        $this->currentCallbacks['DELETE'] = $callback;
    }

    public function patch(\Closure $callback)
    {
        $this->currentCallbacks['PATCH'] = $callback;
    }

    public function options(\Closure $callback)
    {
        $this->currentCallbacks['OPTIONS'] = $callback;
    }

    public function domain()
    {
    }

    public function subdomain()
    {
    }

    public function helper($name, $className = null)
    {
        if($className === null) {
            // Ensure helper exists
            if(!isset($this->_helpers[$name])) {
                throw new \InvalidArgumentException("Requested helper '" . $name ."' not registered.");
            }

            // Instantiate helper if not done already
            if(!is_object($this->_helpers[$name])) {
                $this->_helpers[$name] = new $this->_helpers[$name];
            }

            return $this->_helpers[$name];
        } else {
            $this->_helpers[$name] = $className;
        }
    }

    public function registerResponseHandler()
    {
    }

    public function removeResponseHandler()
    {
    }

    public static function paramInt()
    {
        return function($value) {
            return filter_var($value, FILTER_VALIDATE_INT);
        };
    }

    public static function paramFloat()
    {
        return function($value) {
            return filter_var($value, FILTER_VALIDATE_FLOAT);
        };
    }

    /**
     * 
     * True = "1", "true", "on", "yes"
     * False = "0", "false", "off", "no"
     */
    public static function paramBoolean()
    {
        return function($value) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return (!empty($filtered) && $filtered !== null);
        };
    }

    public static function paramSlug()
    {
        return function($value) {
            return (preg_match("/[a-zA-Z0-9-_]/", $value) > 0);
        };
    }

    public static function paramEmail()
    {
        return function($value) {
            return filter_var($value, FILTER_VALIDATE_EMAIL);
        };
    }
}
