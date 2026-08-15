<?php
namespace CrescoLayer\SiteSettings\Migration;

interface BreakpointUsageScanner {
	/**
	 * @param string[] $devices Breakpoint device keys being removed, e.g. mobile_extra.
	 * @return array Structured usage report.
	 */
	public function scan( array $devices ): array;
}
