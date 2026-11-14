"""Build the OpenCart packages: copies the ArionPay PHP SDK into each extension, then zips it.
   python3 build.py   ->  dist/opencart-4/arionpay.ocmod.zip and dist/opencart-3/arionpay-opencart-3.ocmod.zip"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
DIST = HERE / "dist"
DIST.mkdir(exist_ok=True)

def copy_sdk(dest):
    if dest.exists():
        shutil.rmtree(dest)
    (dest / "src").mkdir(parents=True)
    shutil.copy(SDK / "autoload.php", dest / "autoload.php")
    shutil.copy(SDK / "LICENSE", dest / "LICENSE")
    for f in sorted((SDK / "src").glob("*.php")):
        shutil.copy(f, dest / "src" / f.name)

def zip_dir(src, out, prefix=""):
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(src):
            dirs.sort()
            for name in sorted(files):
                p = pathlib.Path(root) / name
                z.write(p, prefix + str(p.relative_to(src)))
    print(out, os.path.getsize(out), "bytes")

copy_sdk(HERE / "oc4" / "system" / "library" / "arionpay-php")
# OpenCart 4 names the extension folder after the zip file, so the file must be called arionpay.ocmod.zip
(DIST / "opencart-4").mkdir(exist_ok=True)
zip_dir(HERE / "oc4", DIST / "opencart-4" / "arionpay.ocmod.zip")

# OpenCart 3: everything under upload/, copied into the shop's own folders by Extensions > Installer
copy_sdk(HERE / "oc3" / "upload" / "system" / "library" / "arionpay-php")
(DIST / "opencart-3").mkdir(exist_ok=True)
zip_dir(HERE / "oc3", DIST / "opencart-3" / "arionpay-opencart-3.ocmod.zip")
