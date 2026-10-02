import os
import sys
import time
import zipfile
import subprocess
from datetime import datetime

def make_backup(tag="major_milestone_v2_1"):
    project_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    backups_dir = os.path.join(project_root, "backups")
    os.makedirs(backups_dir, exist_ok=True)
    
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    zip_filename = f"mitra_kinerja_{tag}_{timestamp}.zip"
    zip_filepath = os.path.join(backups_dir, zip_filename)
    
    # 1. Dump database if MySQL is accessible
    db_dump_path = os.path.join(backups_dir, f"db_dump_{timestamp}.sql")
    mysqldump_candidates = [
        r"C:\xampp\mysql\bin\mysqldump.exe",
        "mysqldump"
    ]
    
    dump_success = False
    for mysqldump in mysqldump_candidates:
        try:
            with open(db_dump_path, "w", encoding="utf-8") as dump_file:
                res = subprocess.run(
                    [mysqldump, "-u", "root", "mitra_kinerja"],
                    stdout=dump_file,
                    stderr=subprocess.PIPE,
                    timeout=30
                )
                if res.returncode == 0 and os.path.getsize(db_dump_path) > 1000:
                    dump_success = True
                    break
        except Exception:
            continue

    # Fallback to existing static dump if live mysqldump is offline
    if not dump_success:
        static_dump = os.path.join(project_root, "database", "mitra_kinerja_dump.sql")
        if os.path.exists(static_dump) and os.path.getsize(static_dump) > 1000:
            import shutil
            shutil.copyfile(static_dump, db_dump_path)
            dump_success = True
            
    # Directories/files to exclude
    exclude_dirs = {".git", "backups", "__pycache__", ".venv", "node_modules", "tmp"}
    exclude_extensions = {".pyc", ".tmp", ".bak", ".log"}
    
    # 2. Create ZIP archive
    file_count = 0
    with zipfile.ZipFile(zip_filepath, "w", zipfile.ZIP_DEFLATED) as zipf:
        # Include database dump if created
        if dump_success and os.path.exists(db_dump_path):
            zipf.write(db_dump_path, arcname=f"database/dump_mitra_kinerja_{timestamp}.sql")
            file_count += 1
            # Clean up raw sql dump outside zip to keep backups/ tidy
            try:
                os.remove(db_dump_path)
            except OSError:
                pass
                
        for root, dirs, files in os.walk(project_root):
            # Prune excluded directories
            dirs[:] = [d for d in dirs if d not in exclude_dirs]
            
            for f in files:
                ext = os.path.splitext(f)[1].lower()
                if ext in exclude_extensions:
                    continue
                # Skip live cache files except .htaccess
                if "cache" in root and f != ".htaccess":
                    continue
                # Skip temporary sql dumps in database folder
                if f.startswith("backup_") and f.endswith(".sql"):
                    continue
                    
                full_path = os.path.join(root, f)
                rel_path = os.path.relpath(full_path, project_root)
                zipf.write(full_path, arcname=rel_path)
                file_count += 1

    size_mb = os.path.getsize(zip_filepath) / (1024 * 1024)
    print(f"BACKUP_CREATED: {zip_filename}")
    print(f"PATH: {zip_filepath}")
    print(f"FILES_ARCHIVED: {file_count}")
    print(f"SIZE: {size_mb:.2f} MB")
    return zip_filepath

if __name__ == "__main__":
    tag_arg = sys.argv[1] if len(sys.argv) > 1 else "v2_1_full_stable"
    make_backup(tag_arg)
