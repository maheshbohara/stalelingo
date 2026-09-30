<?php
/**
 * Minimal Action Scheduler stubs for static analysis (optional runtime integration, never bundled).
 *
 * @package Stalelingo
 */

/**
 * @param int                  $timestamp Unix timestamp.
 * @param string               $hook      Hook.
 * @param array<mixed>         $args      Args.
 * @param string               $group     Group.
 * @param bool                 $unique    Unique.
 * @param int                  $priority  Priority.
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ): int {
	return 0;
}

/**
 * @param string       $hook   Hook.
 * @param array<mixed> $args   Args.
 * @param string       $group  Group.
 * @param bool         $unique Unique.
 * @param int          $priority Priority.
 */
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ): int {
	return 0;
}

/**
 * @param string            $hook  Hook.
 * @param array<mixed>|null $args  Args.
 * @param string            $group Group.
 */
function as_has_scheduled_action( $hook, $args = null, $group = '' ): bool {
	return false;
}

/**
 * @param string       $hook  Hook.
 * @param array<mixed> $args  Args.
 * @param string       $group Group.
 */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ): void {
}
