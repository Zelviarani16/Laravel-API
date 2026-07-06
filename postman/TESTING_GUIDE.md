# 📋 Panduan Testing Migrasi Supabase Auth - Postman

## 📁 File yang Sudah Dibuat

```
postman/
├── Ticketing_API_Supabase_Auth.postman_collection.json   # Collection dengan 7 test
└── Ticketing_API_Environment.postman_environment.json    # Environment variables
```

---

## 🚀 Langkah Import ke Postman

### Step 1: Import Environment
1. Buka Postman
2. Klik **Environments** (icon gear di kanan atas)
3. Klik **Import**
4. Pilih file: `postman/Ticketing_API_Environment.postman_environment.json`
5. Klik **Save**

### Step 2: Import Collection
1. Klik **Collections** (icon folder di kiri)
2. Klik **Import**
3. Pilih file: `postman/Ticketing_API_Supabase_Auth.postman_collection.json`
4. Collection akan muncul sebagai "Ticketing API - Supabase Auth Migration"

### Step 3: Set Environment
1. Di kanan atas Postman, klik dropdown environment
2. Pilih: **Ticketing API - Supabase Auth**

### Step 4: Konfigurasi User Test
1. Klik **Environments** lagi
2. Edit variable:
   - `userEmail` → email user yang SUDAH ADA di Supabase auth.users
   - `userPassword` → password user tersebut
   - `jwtToken` → biarkan kosong (akan auto-fill dari Test 1)

---

## ⚠️ PRA SYARAT

### Buat User Test di Supabase (jika belum ada)
1. Buka https://supabase.com/dashboard
2. Pilih project `xhvfyknfhsrnbgwnchsp`
3. Go to **Authentication** → **Users**
4. Klik **Add user**
5. Isi:
   - Email: `test@example.com`
   - Password: `password123`
6. Klik **Create user**

---

## 🧪 Jalankan Test Berurutan

> ⚠️ **PENTING**: Jalankan test secara berurutan dari 1-7. Test 2-7 butuh JWT dari Test 1.

### TEST 1 - Get JWT from Supabase
**Tujuan**: Dapat JWT token untuk dipakai di test berikutnya

**Request**:
```
POST https://xhvfyknfhsrnbgwnchsp.supabase.co/auth/v1/token?grant_type=password
Headers:
  apikey: eyJhbGci... (anon key)
  Content-Type: application/json
Body:
{
  "email": "test@example.com",
  "password": "password123"
}
```

**Expected Response** (200):
```json
{
  "access_token": "eyJhbGc...",
  "refresh_token": "...",
  "expires_in": 3600,
  "expires_at": 1234567890,
  "token_type": "bearer",
  "user": {
    "id": "uuid-user-disini",
    "email": "test@example.com",
    ...
  }
}
```

**Test akan auto-save** `access_token` ke variable `{{jwtToken}}`

---

### TEST 2 - Register (POST /auth/register)
**Tujuan**: Verify middleware baca JWT + insert ke public.users

**Request**:
```
POST http://localhost:8000/api/auth/register
Headers:
  Authorization: Bearer {{jwtToken}}  ← dari Test 1
  Content-Type: application/json
Body:
{
  "name": "Test User",
  "role": "user"
}
```

**Expected Response** (201):
```json
{
  "message": "Registrasi berhasil",
  "user": {
    "id": "uuid-user-yang-sama-dengan-jwt",
    "name": "Test User",
    "email": "test@example.com",
    "role": "user"
  }
}
```

**Verifikasi Manual**:
1. Buka Supabase Dashboard → Table Editor → `public.users`
2. Pastikan row baru masuk dengan `id` yang SAMA dengan `sub` claim di JWT

---

### TEST 3 - Profile (GET /auth/profile)
**Tujuan**: Verify `$request->user()` berfungsi via middleware baru

**Request**:
```
GET http://localhost:8000/api/auth/profile
Headers:
  Authorization: Bearer {{jwtToken}}
```

**Expected Response** (200):
```json
{
  "user": {
    "id": "uuid-user",
    "name": "Test User",
    "email": "test@example.com",
    "role": "user"
  }
}
```

---

### TEST 4 - Get Tickets (GET /tickets)
**Tujuan**: Verify middleware berfungsi di route non-auth

**Request**:
```
GET http://localhost:8000/api/tickets
Headers:
  Authorization: Bearer {{jwtToken}}
```

**Expected Response** (200):
```json
{
  "tickets": [
    {
      "id": 1,
      "title": "...",
      "user_id": "uuid-user",
      ...
    }
  ]
}
```

---

### TEST 5 - Without Token (SHOULD FAIL)
**Tujuan**: Verify middleware reject request tanpa JWT

**Request**:
```
GET http://localhost:8000/api/tickets
Headers: (KOSONG - tidak ada Authorization)
```

**Expected Response** (401):
```json
{
  "message": "Token tidak ditemukan. Silakan login terlebih dahulu."
}
```

---

### TEST 6 - Invalid Token (SHOULD FAIL)
**Tujuan**: Verify middleware reject JWT palsu

**Request**:
```
GET http://localhost:8000/api/tickets
Headers:
  Authorization: Bearer tokenpalsu12345
```

**Expected Response** (401):
```json
{
  "message": "Token tidak valid..."
}
```

---

### TEST 7 - Create Ticket (POST /tickets)
**Tujuan**: Verify `$request->user()->id` berfungsi untuk isi `user_id`

**Request**:
```
POST http://localhost:8000/api/tickets
Headers:
  Authorization: Bearer {{jwtToken}}
  Content-Type: application/json
Body:
{
  "title": "Test Tiket",
  "description": "Ini test tiket setelah migrasi auth",
  "category": "Software",
  "priority": "low"
}
```

**Expected Response** (201):
```json
{
  "message": "Tiket berhasil dibuat",
  "ticket": {
    "id": 123,
    "title": "Test Tiket",
    "description": "Ini test tiket setelah migrasi auth",
    "user_id": "uuid-user-dari-jwt",
    "category": "Software",
    "priority": "low",
    ...
  }
}
```

**Verifikasi Manual**:
1. Cek `user_id` di response = UUID dari JWT `sub` claim

---

## 📊 Format Report Testing

Isi tabel ini setelah jalankan semua test:

| Test | Endpoint | Status | Status Code | Notes |
|------|----------|--------|-------------|-------|
| 1 | GET JWT Supabase | ✅/❌ | ___ | JWT: `{{jwtToken}}` |
| 2 | POST /auth/register | ✅/❌ | ___ | User ID: ___ |
| 3 | GET /auth/profile | ✅/❌ | ___ | |
| 4 | GET /tickets | ✅/❌ | ___ | |
| 5 | GET /tickets (no token) | ✅/❌ | ___ | Should be 401 |
| 6 | GET /tickets (invalid) | ✅/❌ | ___ | Should be 401 |
| 7 | POST /tickets | ✅/❌ | ___ | user_id: ___ |

---

## 🔧 Troubleshooting

### Error: "Token tidak ditemukan"
- Pastikan Test 1 berhasil dan `{{jwtToken}}` terisi
- Cek Collection → Authorization → Inherit from parent

### Error: "Token tidak valid"
- JWT secret mungkin salah atau expired
- Cek `.env` → `SUPABASE_JWT_SECRET` sudah ada dan benar
- Jalankan `php artisan config:clear`

### Error: "User tidak ditemukan"
- User belum ada di `public.users`
- Run Test 2 lagi (akan update jika user sudah ada)

### Error: Connection Refused localhost:8000
- Laravel server belum jalan
- Run: `php artisan serve`

---

## ✅ Kriteria Success

Semua test harus PASS:
- ✅ Test 1: Dapat JWT dari Supabase
- ✅ Test 2: Register berhasil, user masuk ke public.users
- ✅ Test 3: Profile mengembalikan user dari JWT
- ✅ Test 4: Tickets accessible dengan JWT valid
- ✅ Test 5: Request tanpa token di-reject (401)
- ✅ Test 6: Request dengan token invalid di-reject (401)
- ✅ Test 7: Tiket dibuat dengan `user_id` dari JWT
