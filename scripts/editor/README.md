# Article editor bundle

`public/assets/vendor/toastui/toastui-editor.js` is Toast UI Editor 3.2.2 (MIT) with its ProseMirror dependencies bundled into one file, because the npm build expects them as separate globals. Rebuild (dev time only; the app needs no Node):

    mkdir /tmp/editor && cd /tmp/editor && npm init -y && npm i @toast-ui/editor@3.2.2 esbuild
    cp <repo>/scripts/editor/entry.js . && npx esbuild entry.js --bundle --minify --format=iife --target=es2019 --legal-comments=none --loader:.css=empty --outfile=toastui-editor.js

The stylesheets next to it (`toastui-editor.css`, `toastui-editor-dark.css`) come unchanged from the same npm package.
