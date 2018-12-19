#!/bin/sh

# First you have to link/copy /skins directory from Roundcube repo
# into ./skins here

# Note: You can remove -x option to generate non-minified file
#       (remember to remove ".min" from the output file name)

lessc --relative-urls -x plugins/libkolab/skins/elastic/libkolab.less > plugins/libkolab/skins/elastic/libkolab.min.css
