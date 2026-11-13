#!/bin/bash
# Double-click this file on your Mac to install the `sdc` uploader.
set -e
cd "$(dirname "$0")"
if ! /usr/bin/python3 -c 'import sys' >/dev/null 2>&1; then
  echo "Python 3 isn't installed yet. A window will ask to install Apple's Command Line Tools."
  echo "Click Install, wait for it to finish, then double-click this file again."
  xcode-select --install || true
  exit 1
fi
mkdir -p "$HOME/.sdc/bin"
cp sdc.py "$HOME/.sdc/sdc.py"
cat > "$HOME/.sdc/bin/sdc" <<'EOS'
#!/bin/bash
exec /usr/bin/python3 "$HOME/.sdc/sdc.py" "$@"
EOS
chmod +x "$HOME/.sdc/bin/sdc"
for rc in "$HOME/.zshrc" "$HOME/.bash_profile"; do
  grep -q '.sdc/bin' "$rc" 2>/dev/null || echo 'export PATH="$HOME/.sdc/bin:$PATH"' >> "$rc"
done
export PATH="$HOME/.sdc/bin:$PATH"
echo ""
echo "✓ sdc installed."
echo ""
echo "Now connect it to your site (you need an uploader key from Admin → Site copy → Uploader keys):"
echo ""
sdc setup
echo ""
echo "All set. Open a new Terminal window and type:  sdc"
