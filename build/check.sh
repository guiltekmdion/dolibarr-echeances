#!/bin/sh
# Checks of the module with the tools and the setup of Dolibarr: phpcs (dev/setup/codesniffer/ruleset.xml), phpstan (phpstan.neon.dist,
# level and baseline of Dolibarr) and phan (dev/tools/phan/config.php, see build/phan.php). Run by the CI, and by hand:
#   build/check.sh <root of a throwaway Dolibarr tree> [phpcs] [phpstan] [phan]     (all three when none is named)
# The module is copied as htdocs/installments of this tree (the tools of Dolibarr skip htdocs/custom), without its tests and build
# files; the copy stays there. The binaries can be given by PHPCS, PHPSTAN and PHAN. Exit 1 if a tool finds something.
D="$(cd "$1" && pwd)" || exit 1
shift
M="$(cd "$(dirname "$0")/.." && pwd)"
T="$D/htdocs/installments"
[ -e "$T" ] && { echo "$T exists: use a throwaway Dolibarr tree"; exit 1; }
mkdir "$T" || exit 1
for f in "$M"/*; do
	case "${f##*/}" in
		test|build) ;;
		*) cp -a "$f" "$T/" ;;
	esac
done
cd "$D" || exit 1
err=0
for tool in ${*:-phpcs phpstan phan}; do
	echo "== $tool"
	case $tool in
		phpcs) "${PHPCS:-phpcs}" -s -d memory_limit=-1 --extensions=php --standard=dev/setup/codesniffer/ruleset.xml htdocs/installments || err=1 ;;
		phpstan) "${PHPSTAN:-phpstan}" analyse -c phpstan.neon.dist -a dev/build/phpstan/bootstrap_action.php --no-progress --memory-limit=-1 htdocs/installments || err=1 ;;
		phan) "${PHAN:-phan}" --config-file "$M/build/phan.php" --include-analysis-file-list "$(find htdocs/installments -name '*.php' | paste -sd, -)" \
			--no-progress-bar --minimum-target-php-version 8.1 || err=1 ;;
	esac
done
exit $err
