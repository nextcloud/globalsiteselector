# SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
app_name=globalsiteselector

project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
version+=2.7.0

all: appstore

clean:
	rm -rf $(build_dir)
	rm -fr vendor/
	rm -fr vendor-bin/csfixer/vendor/
	rm -fr vendor-bin/mozart/vendor/
	rm -fr vendor-bin/phpunit/vendor/
	rm -fr vendor-bin/psalm/vendor/

cs-check: composer-dev
	composer cs:check

cs-fix: composer-dev
	composer cs:fix

composer:
	composer install
	composer upgrade

composer-dev:
	composer install --dev
	composer upgrade --dev

appstore: clean composer release

release:
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/build \
	--exclude=/docs \
	--exclude=/translationfiles \
	--exclude=.tx \
	--exclude=.idea \
	--exclude=.php-cs-fixer.dist.php \
	--exclude=.php-cs-fixer.cache \
	--exclude=CHANGELOG.md \
	--exclude=composer.json \
	--exclude=composer.lock \
	--exclude=psalm.xml \
	--exclude=README.md \
	--exclude=/tests \
	--exclude=.git \
	--exclude=.github \
	--exclude=/l10n/l10n.pl \
	--exclude=/CONTRIBUTING.md \
	--exclude=/issue_template.md \
	--exclude=.gitattributes \
	--exclude=.gitignore \
	--exclude=.scrutinizer.yml \
	--exclude=vendor \
	--exclude=vendor-bin \
	--exclude=.travis.yml \
	--exclude=/Makefile \
	--exclude=.drone.yml \
	$(project_dir)/ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name)-$(version).tar.gz \
		-C $(sign_dir) $(app_name)
