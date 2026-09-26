import os
import sys
import tempfile

# изолированная база и отладочный пользователь для тестов — до импорта app
_tmp = tempfile.mkdtemp()
# TEST_DATABASE_URL=mysql+aiomysql://... — прогон тестов на MySQL/MariaDB
os.environ["DATABASE_URL"] = os.getenv("TEST_DATABASE_URL") or f"sqlite+aiosqlite:///{_tmp}/test.db"
os.environ["SECRET_KEY"] = "test-secret"
os.environ["DB_NULLPOOL"] = "1"
os.environ["ENGINE_ENABLED"] = "0"
os.environ["BOT_TOKEN"] = ""
os.environ["DEV_USER_ID"] = "777"
os.environ.setdefault("ENCRYPTION_KEY", "q2Zq5b8xkq1nXw0hO0mY9Uj6o1Gq3m3u3x5eQyP8dHk=")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
