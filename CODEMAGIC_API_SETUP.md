# 🔑 إعداد Codemagic API

## API Key المقدم:

```
WeH1IbZq9br2NyVZwb59Eq_6wHETFV2IVPLjLboigqs
```

---

## 📝 خطوات الإعداد:

### الخطوة 1: إضافة API Key في Codemagic

1. اذهب إلى **Codemagic** > **Settings**
2. **API tokens** أو **API keys**
3. **Add new token** أو **Create API key**
4. أدخل:
   - **Name:** `carby-ios-api`
   - **Token:** `WeH1IbZq9br2NyVZwb59Eq_6wHETFV2IVPLjLboigqs`
5. **Save**

### الخطوة 2: تغيير Build Configuration (مهم جداً!)

**⚠️ هذا أهم خطوة - بدونها لن يعمل البناء!**

1. **Settings** > **Build configuration**
2. **غيّر من "Flutter" إلى "Advanced"**
3. **Configuration file:** `codemagic.yaml`
4. **Branch:** `main`
5. **Save**

### الخطوة 3: إعداد App Store Connect Integration

1. **Settings** > **Integrations**
2. **App Store Connect** > **Add integration**
3. أدخل:
   - **Integration name:** `codemagic`
   - **Key ID**
   - **Issuer ID**
   - **Private key** (محتوى ملف .p8)
4. **Save**

### الخطوة 4: إعادة تشغيل البناء

1. **Start new build**
2. **Branch:** `main`
3. **Workflow:** `ios-workflow`
4. **Start build**

---

## 🔧 استخدام Codemagic CLI (اختياري):

إذا أردت استخدام CLI للتحكم في البناءات:

```bash
# تثبيت Codemagic CLI
npm install -g codemagic-cli

# تسجيل الدخول باستخدام API key
codemagic login --api-key WeH1IbZq9br2NyVZwb59Eq_6wHETFV2IVPLjLboigqs

# بدء بناء جديد
codemagic builds start --app-id YOUR_APP_ID --workflow ios-workflow --branch main
```

---

## 📋 قائمة التحقق:

- [ ] API Key مُضاف في Codemagic Settings
- [ ] Build configuration: **Advanced** (ليس Flutter!)
- [ ] Configuration file: `codemagic.yaml`
- [ ] App Store Connect Integration مُعد
- [ ] Integration name: `codemagic`

---

## ⚠️ ملاحظات مهمة:

1. **API Key وحده لا يكفي** - يجب تغيير Build configuration إلى Advanced
2. **بدون تغيير Build configuration، سيستمر الخطأ**
3. **API Key يستخدم للـ CLI أو API calls، لكن البناء يحتاج Advanced configuration**

---

**الآن اذهب إلى Codemagic Settings:**
1. أضف API Key
2. **غيّر Build configuration من Flutter إلى Advanced**
3. أعد تشغيل البناء

