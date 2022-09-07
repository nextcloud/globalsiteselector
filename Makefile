app_name=globalsiteselector

project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
version+=2.2.0

all: release

clean:
	rm -rf $(build_dir)

release: clean
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/build \
	--exclude=/docs \
	--exclude=/translationfiles \
	--exclude=.tx \
	--exclude=/tests \
	--exclude=.git \
	--exclude=.github \
	--exclude=/l10n/l10n.pl \
	--exclude=/CONTRIBUTING.md \
	--exclude=/issue_template.md \
	--exclude=.gitattributes \
	--exclude=.gitignore \
	--exclude=.scrutinizer.yml \
	--exclude=.travis.yml \
	--exclude=/Makefile \
	--exclude=.drone.yml \
	$(project_dir)/ $(sign_dir)/$(app_name)
	tar -czf $(build_dir)/$(app_name)-$(version).tar.gz \
		-C $(sign_dir) $(app_name)
