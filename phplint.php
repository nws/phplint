#!/usr/bin/env php
<?php

/* ALL_EXIT_OK */

function filter_whitespace($t) {
	return !is_array($t) || $t[0] !== T_WHITESPACE;
}

function get_token($reset = false, $source = null) {
	static $tokens = array();
	static $i = 0;
	static $tc = 0;

	if ($reset === 'PUSH_BACK') {
		$i--;
		if ($i < 0) {
			$i = 0;
		}
		return;
	}

	if ($reset) {
		$i = 0;
		if ($source !== null) {
			$tokens = array_values(array_filter(token_get_all($source), 'filter_whitespace'));
			$tc = count($tokens);
		}
		return;
	}

	while ($tc > $i) {
		$t = $tokens[$i];
		$i++;

		if (is_scalar($t)) {
			return array($t, $t, null);
		}
		else if ($t[0] == T_CURLY_OPEN) {
			return array('{', '{', null);
		}
		else {
			return $t;
		}
	}
}

function check_no_block_statements() {
	$statements = array(
		T_IF => 1,
		T_FOREACH => 1,
		T_WHILE => 1,
		T_ELSE => 1,
		T_ELSEIF => 1,
		T_FOR => 1,
	);
	$errors = array();

	while ($t = get_token()) {
		if (isset($statements[ $t[0] ])) {
			if ($t[0] == T_ELSE) {
				$next = get_token();
				if ($next[0] == T_IF) {
					$t = $next;
				}
				else {
					get_token('PUSH_BACK');
				}
			}
			if ($t[0] != T_ELSE) {
				$parens = 0;
				while ($c = get_token()) {
					if ($c[0] == '(') {
						$parens++;
						continue;
					}
					if ($c[0] == ')') {
						$parens--;
						if ($parens == 0) {
							break;
						}
						continue;
					}
				}
			}
			$next = get_token();
			if ($next[0] != '{' && $next[0] != ';') {
				get_token('PUSH_BACK');
				$errors[] = $t[2];
			}
		}
	}
	return $errors;
}

function check_foreach_ref() {
	$curlies = $parens = 0;
	$foreach_header = array();
	$foreaches_with_ref = array();
	$check_next_token_is_unset = false;

	$errors = array();
	while ($t = get_token()) {
		if ($check_next_token_is_unset) {
			if ($t[0] != T_UNSET) {
				$errors[] = $check_next_token_is_unset['t'][2];
			}
			else {
				get_token('PUSH_BACK');
			}
			$check_next_token_is_unset = false;
			continue;
		}

		if ($t[0] == T_FOREACH) {
			$foreach_header = array();
			while ($t_foreach = get_token()) {
				if ($t_foreach[0] == '(') {
					$parens++;
					continue;
				}
				if ($t_foreach[0] == ')') {
					$parens--;

					if ($parens == 0) {
						array_pop($foreach_header); // the varible
						$is_ref = array_pop($foreach_header);
						// if we're after $k => $v, this is & or =>
						// if we're after as $v, this is & or as
						$foreaches_with_ref[] = array(
							'is_ref' => $is_ref[0] == '&',
							'curlies' => $curlies,
							't' => $t,
						);
						break;
					}
					continue;
				}
				$foreach_header[] = $t_foreach;
			}
			continue;
		}
		if ($t[0] == '{') {
			$curlies++;
			continue;
		}
		if ($t[0] == '}') {
			$curlies--;
			if (!empty($foreaches_with_ref)) {
				$last_foreach = $foreaches_with_ref[ count($foreaches_with_ref)-1 ];
				if ($curlies == $last_foreach['curlies']) {
					if ($last_foreach['is_ref']) {
						$check_next_token_is_unset = $last_foreach;
					}
					array_pop($foreaches_with_ref);
				}
			}
			continue;
		}
	}
	return $errors;
}

// no. instead, find closing ) of empty(), go back one
// if that one is a ), this is a function call i think. and that's wrong.
function check_nonvar_in_empty() {
	$errors = array();
	while ($t = get_token()) {
		if ($t[0] == T_EMPTY) {
			$parens = 0;
			$t_prev = null;
			while ($t_inner = get_token()) {
				if ($t_inner[0] == '(') {
					$parens++;
				}
				else if ($t_inner[0] == ')') {
					$parens--;
					if ($parens == 0) {
						if ($t_prev[0] == ')') {
							$errors[] = $t[2];
						}
						break;
					}
				}
				$t_prev = $t_inner;
			}
		}
	}
	return $errors;
}

function check_bad_exits() {
	$errors = array();
	while ($t = get_token()) {
		if ($t[0] == T_COMMENT) {
			if (strpos($t[1], 'ALL_EXIT_OK') !== false
				|| strpos($t[1], 'ALL_DIE_OK') !== false)
			{
				break;
			}
		}
		if ($t[0] == T_EXIT) {
			$exit_is_error = true;
			while ($semicolon_t = get_token()) {
				if ($semicolon_t[0] == T_COMMENT) {
					if (strpos($semicolon_t[1], 'EXIT_OK') !== false
						|| strpos($semicolon_t[1], 'DIE_OK') !== false)
					{
						$exit_is_error = false;
					}
				}
				if ($semicolon_t[0] == ';') {
					if ($exit_is_error) {
						$errors[] = $t[2];
					}
					break;
				}
			}
		}
	}
	return $errors;
}

function is_bad_call($loop, $token, $pattern, $in_header) {
	$str = $token[1];
	$tcount = 0;
	while ($t = get_token()) {
		$tcount++;
		$str .= $t[1];
		if ($t[0] === '(') {
			break;
		}
	}

	$match = preg_match($pattern, $str);

	while ($tcount-- > 0) { // restore state
		get_token('PUSH_BACK');
	}

	return $match ? $token[2] : null;
}

function check_fncall_in_loops($pattern) {
	$pattern = '#^('.$pattern.')\($#i';

	$the_loops = array(
		T_DO => 1,
		T_FOR => 1,
		T_FOREACH => 1,
		T_WHILE => 1,
	);

	$errors = array();
	$loops = array();
	$curlies = 0;
	$just_after_loop_head = $eat_head = false;

	while ($t = get_token()) {
		if (isset($the_loops[ $t[0] ])) {
			$loops[] = array(
				'token' => $t,
				'curlies' => $curlies,
			);
			$eat_head = true;
		}
		else if ($loops) {
			if ($eat_head) {
				$eat_head = false;

				if ($t[0] === '(') {
					// loop has head
					$parens = 1;
					$skip_the_rest = false;
					while ($lh_t = get_token()) {
						if ($lh_t[0] === '(') {
							$parens++;
						}
						else if ($lh_t[0] === ')') {
							$parens--;
							if ($parens == 0) {
								$just_after_loop_head = true;
								break;
							}
						}
						else if ($lh_t[0] === T_AS && $loops[ count($loops) - 1 ]['token'][0] === T_FOREACH) {
							$skip_the_rest = true;
						}

						if ($skip_the_rest) {
							continue;
						}

						if ($lh_t[0] !== T_STRING) { // function calls start with T_STRING
							continue;
						}

						// we are inside a loop's head
						if ($e = is_bad_call($loops[ count($loops) - 1 ]['token'], $lh_t, $pattern, true)) {
							$errors[] = $e;
						}
					}
				}
				else {
					get_token('PUSH_BACK');
				}
			}
			else if ($just_after_loop_head) {
				if ($t[0] === '{') {
					$curlies++;
				}
				else if ($t[0] === ';') {
					if ($curlies == $loops[ count($loops) - 1 ]['curlies']) {
						// loop's over bitches
						array_pop($loops);
					}
				}
				else {
					// perhaps i could eat up until ; here, and call is_bad_call
					// that way we'd handle blockless loops too
					die("single-statement loop, we dont like you\n");
				}
				$just_after_loop_head = false;
			}
			else if ($t[0] === '{') {
				$curlies++;
			}
			else if ($t[0] === '}') {
				$curlies--;
				if ($curlies == $loops[ count($loops) - 1 ]['curlies']) {
					// loop's over bitches
					array_pop($loops);
				}
			}
			else if ($t[0] === T_STRING) {
				// we are inside a loop and this *could* be the start of a function call
				if ($e = is_bad_call($loops[ count($loops) - 1]['token'], $t, $pattern, false)) {
					$errors[] = $e;
				}
			}
		}
	}

	return $errors;
}

function ignored($fn, $patterns) {
	foreach ($patterns as $p) {
		if (fnmatch($p, $fn)) {
			return true;
		}
	}
	return false;
}

$checks = array(
	'fncall_in_loops' => array(
		'fn' => 'check_fncall_in_loops',
		'desc' => 'warn if specific calls are made inside a loop',
		'default_arg' => 'm|sel::\w+',
	),
	'unset' => array(
		'fn' => 'check_foreach_ref',
		'desc' => 'warn if a foreach ($array as &$ref) ... has no unset($ref) immediately after',
	),
	'block' => array(
		'fn' => 'check_no_block_statements',
		'desc' => 'warn if you used one-statement control-structures such as if (cond) do_something();',
	),
	'empty' => array(
		'fn' => 'check_nonvar_in_empty',
		'desc' => 'warn if you seem to be calling a function inside empty()',
	),
	'exit' => array(
		'fn' => 'check_bad_exits',
		'desc' => "warn if there is exit or die in your code.\nuse stk_exit or stk_die instead.\ncan be silenced by /* EXIT_OK */ or /* DIE_OK */ comment after the exit/die,\nbut before the statement-ending semicolon.\ncan be silenced for the rest of the file by /* ALL_EXIT_OK */ or /* ALL_DIE_OK */ at the top.",
	),
);

$run_checks = array();
while ($argc > 1 && $argv[1][0] == '-') {
	$opt = null;
	list($chk) = array_splice($argv, 1, 1);
	$chk = substr($chk, 1);
	if (strpos($chk, '=') !== false) {
		list($chk, $opt) = explode('=', $chk, 2);
	}
	if (!isset($checks[$chk])) {
		die("invalid check $chk passed\n");
	}
	$argc--;

	if ($opt === null && isset($checks[$chk]['default_arg'])) {
		$opt = $checks[$chk]['default_arg'];
	}

	$run_checks[$chk] = $opt;
}

if (empty($run_checks)) {
	foreach ($checks as $name => $def) {
		$run_checks[ $name ] = isset($def['default_arg'])
			? $def['default_arg']
			: null;
	}
}

if ($argc > 1) {
	$ignore = array();
	if (file_exists('.phplintignore')) {
		$ignore = file('.phplintignore', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
	}

	array_shift($argv);
	foreach ($argv as $a) {
		if (ignored($a, $ignore)) {
			continue;
		}

		$errors = array();
		get_token(true, file_get_contents($a));

		foreach ($run_checks as $key => $opt) {
			$fn = $checks[$key]['fn'];
			get_token(true);
			$check_errors = $fn($opt);
			if (!empty($check_errors)) {
				$errors[$key] = $check_errors;
			}
		}

		if (!empty($errors)) {
			echo $a, ': ';
			foreach ($errors as $k => $v) {
				echo "$k: ", implode(', ', $v), ' ';
			}
			echo "\n";
		}
	}
}
else {
	$opts = array();
	foreach ($checks as $k => $c) {
		$opts[] = "[-$k]";
	}
	echo $argv[0], ' ', implode(' ', $opts), " files...\n";
	echo " by default, all checks are on\n";
	foreach ($checks as $k => $c) {
		echo " -$k:";
		if (isset($c['default_arg'])) {
			echo " (default: {$c['default_arg']})";
		}
		echo "\n";
		foreach (explode("\n", $c['desc']) as $d) {
			echo "   $d\n";
		}
	}
}
