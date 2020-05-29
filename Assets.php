// @var array $data
		$data = [];
		
		// callbacks
		$callbacks = [];
		
		// merge data
		foreach (self::$phpVarsData as $vars) :
			
			// add callback
			if (isset($vars['callback'])) $callbacks[] = $vars['callback'] . '.call();';
			
			// merge data 
			$data = array_merge($data, $vars);
			
		endforeach;
		
		// add callbacks
		if (count($callbacks) > 0) self::$jsScripts[] = '<script type="text/deffered">'.implode(' ', $callbacks).'</script>';
		
		// return string 
		return '<script type="text/deffered">let phpvars = '.json_encode($data).';</script>';
