<template>
    <div v-loading="loading">
        <div class="page-card">
            <div class="page-card-title">
                <el-icon><Files /></el-icon>
                {{ t('settings.storageTitle') }}
            </div>

            <el-form label-position="top" @submit.prevent="save">
                <el-form-item :label="t('settings.storageType')">
                    <el-select v-model="form.storage_type" style="max-width: 360px">
                        <el-option :label="t('settings.storageTypeS3')" value="s3" />
                        <el-option :label="t('settings.storageTypeLocal')" value="local" />
                    </el-select>
                </el-form-item>

                <el-collapse-transition>
                    <div v-if="form.storage_type === 's3'">
                        <el-row :gutter="16">
                            <el-col :md="8" :sm="24">
                                <el-form-item :label="t('settings.s3Key')">
                                    <el-input v-model="form.s3.S3_KEY" />
                                </el-form-item>
                            </el-col>
                            <el-col :md="8" :sm="24">
                                <el-form-item :label="t('settings.s3Password')">
                                    <el-input v-model="form.s3.S3_PASSWORD" show-password />
                                </el-form-item>
                            </el-col>
                            <el-col :md="8" :sm="24">
                                <el-form-item :label="t('settings.s3Bucket')">
                                    <el-input v-model="form.s3.S3_BUCKET" />
                                </el-form-item>
                            </el-col>
                            <el-col :md="8" :sm="24">
                                <el-form-item>
                                    <template #label>
                                        <div style="display: flex; gap: 12px; align-items: center">
                                            <span>{{ t('settings.s3Region') }}</span>
                                            <el-checkbox v-model="customRegion">
                                                {{ t('settings.customRegion') }}
                                            </el-checkbox>
                                        </div>
                                    </template>
                                    <el-input
                                        v-if="customRegion"
                                        v-model="form.s3.S3_REGION"
                                        placeholder="custom-region-id"
                                    />
                                    <el-select v-else v-model="form.s3.S3_REGION" style="width: 100%">
                                        <el-option
                                            v-for="r in standardRegions"
                                            :key="r"
                                            :label="r"
                                            :value="r"
                                        />
                                    </el-select>
                                </el-form-item>
                            </el-col>
                            <el-col :md="8" :sm="24">
                                <el-form-item>
                                    <template #label>
                                        <div style="display: flex; gap: 12px; align-items: center">
                                            <span>{{ t('settings.s3Endpoint') }}</span>
                                            <el-checkbox v-model="customEndpoint">
                                                {{ t('settings.customEndpoint') }}
                                            </el-checkbox>
                                            <el-tooltip
                                                placement="top"
                                                :content="t('settings.customEndpointDetails')"
                                                raw-content
                                            >
                                                <el-icon style="color: #6b7280; cursor: help">
                                                    <InfoFilled />
                                                </el-icon>
                                            </el-tooltip>
                                        </div>
                                    </template>
                                    <el-input
                                        v-model="form.s3.S3_ENDPOINT"
                                        :disabled="!customEndpoint"
                                        :placeholder="customEndpoint ? 'https://s3.example.com' : t('settings.endpointDefault')"
                                    />
                                </el-form-item>
                            </el-col>
                        </el-row>
                    </div>
                </el-collapse-transition>

                <el-form-item>
                    <el-checkbox v-model="form.cache_extension">
                        {{ t('settings.cacheExtension') }}
                    </el-checkbox>
                    <el-tooltip
                        placement="right"
                        :content="t('settings.cacheExtensionDetails')"
                        raw-content
                    >
                        <el-icon style="margin-left: 6px; color: #6b7280; cursor: help">
                            <InfoFilled />
                        </el-icon>
                    </el-tooltip>
                </el-form-item>

                <el-form-item>
                    <el-checkbox v-model="form.write_log">
                        {{ t('settings.writeLog') }}
                    </el-checkbox>
                </el-form-item>

                <el-alert
                    v-if="showCacheDetails"
                    type="info"
                    :closable="false"
                    style="margin-bottom: 16px"
                >
                    <div style="white-space: pre-line">{{ t('settings.cacheExtensionDetails') }}</div>
                </el-alert>

                <el-button type="primary" :loading="saving" @click="save">
                    {{ t('common.apply') }}
                </el-button>
            </el-form>
        </div>

        <div class="page-card">
            <div class="page-card-title">
                <el-icon><Operation /></el-icon>
                {{ t('settings.dangerZone') }}
            </div>

            <div style="display: flex; gap: 12px; flex-wrap: wrap">
                <el-button
                    type="success"
                    plain
                    :loading="resettingStatus"
                    @click="resetProfileStatus"
                >
                    <el-icon style="margin-right: 4px"><RefreshRight /></el-icon>
                    {{ t('settings.resetProfileStatus') }}
                </el-button>

                <el-button type="warning" plain :loading="migrating" @click="runMigration">
                    <el-icon style="margin-right: 4px"><DataBoard /></el-icon>
                    {{ t('settings.runMigration') }}
                </el-button>

                <el-button type="info" plain @click="autoUpdate">
                    <el-icon style="margin-right: 4px"><Upload /></el-icon>
                    {{ t('settings.autoUpdate') }}
                </el-button>

                <el-upload
                    ref="uploadRef"
                    :auto-upload="false"
                    :show-file-list="false"
                    accept=".zip"
                    :on-change="onUpdateFileSelected"
                >
                    <el-button type="primary" plain :loading="uploadingUpdate">
                        <el-icon style="margin-right: 4px"><UploadFilled /></el-icon>
                        {{ t('settings.uploadUpdate') }}
                    </el-button>
                </el-upload>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ElMessage, ElMessageBox } from 'element-plus';
import { http } from '../api/http';

const { t } = useI18n();
const config = window.__APP_CONFIG__ || {};

const standardRegions = [
    'APEast1', 'AFSouth1', 'APNortheast1', 'APNortheast2', 'APNortheast3',
    'APSouth1', 'APSoutheast1', 'APSoutheast2', 'CACentral1', 'CNNorth1',
    'CNNorthWest1', 'EUCentral1', 'EUNorth1', 'EUSouth1', 'EUWest1',
    'EUWest2', 'EUWest3', 'MESouth1', 'SAEast1', 'USEast1',
    'USEast2', 'USGovCloudEast1', 'USGovCloudWest1', 'USIsobEast1',
    'USIsoEast1', 'USWest1', 'USWest2',
];

const loading = ref(false);
const saving = ref(false);
const resettingStatus = ref(false);
const migrating = ref(false);
const customRegion = ref(false);
const customEndpoint = ref(false);
const showCacheDetails = ref(false);
const uploadingUpdate = ref(false);
const uploadRef = ref(null);

const form = reactive({
    storage_type: 'local',
    s3: { S3_KEY: '', S3_PASSWORD: '', S3_BUCKET: '', S3_REGION: '', S3_ENDPOINT: '', S3_ENDPOINT_ENABLED: 'off' },
    cache_extension: false,
    write_log: false,
});

async function fetchSettings() {
    loading.value = true;
    try {
        const { data } = await http.get('/settings');
        if (data?.success) {
            form.storage_type = data.data.storage_type;
            form.s3 = { ...form.s3, ...(data.data.s3 || {}) };
            form.cache_extension = data.data.cache_extension === 'on';
            form.write_log = data.data.write_log === 'on';
            customRegion.value = !!form.s3.S3_REGION && !standardRegions.includes(form.s3.S3_REGION);
            customEndpoint.value = form.s3.S3_ENDPOINT_ENABLED === 'on';
        }
    } catch (err) {
        ElMessage.error(err?.response?.data?.message || t('common.error'));
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const payload = {
            storage_type: form.storage_type,
            cache_extension: form.cache_extension,
            write_log: form.write_log,
            S3_KEY: form.s3.S3_KEY,
            S3_PASSWORD: form.s3.S3_PASSWORD,
            S3_BUCKET: form.s3.S3_BUCKET,
            S3_REGION: form.s3.S3_REGION,
            S3_ENDPOINT: form.s3.S3_ENDPOINT,
            S3_ENDPOINT_ENABLED: customEndpoint.value,
        };
        const { data } = await http.post('/settings', payload);
        ElMessage.success(data?.message || t('settings.saved'));
    } catch (err) {
        ElMessage.error(err?.response?.data?.message || t('common.error'));
    } finally {
        saving.value = false;
    }
}

async function resetProfileStatus() {
    try {
        await ElMessageBox.confirm(t('settings.resetProfileStatusConfirm'), t('common.confirm'), {
            type: 'warning',
        });
    } catch {
        return;
    }
    resettingStatus.value = true;
    try {
        const { data } = await http.post('/reset-profile-status');
        ElMessage.success(data?.message || t('common.success'));
    } catch (err) {
        ElMessage.error(err?.response?.data?.message || t('common.error'));
    } finally {
        resettingStatus.value = false;
    }
}

async function runMigration() {
    try {
        await ElMessageBox.confirm(t('settings.runMigrationConfirm'), t('common.confirm'), {
            type: 'warning',
        });
    } catch {
        return;
    }
    migrating.value = true;
    try {
        const { data } = await http.post('/run-migrations');
        if (data?.success) ElMessage.success(data.message || t('common.success'));
        else ElMessage.error(data?.message || t('common.error'));
    } catch (err) {
        ElMessage.error(err?.response?.data?.message || t('common.error'));
    } finally {
        migrating.value = false;
    }
}

async function autoUpdate() {
    try {
        await ElMessageBox.confirm(t('settings.autoUpdateConfirm'), t('common.confirm'), {
            type: 'warning',
        });
    } catch {
        return;
    }
    // Auto-update page is a GET endpoint that streams its own response — open it.
    window.open(`${config.baseUrl}/auto-update`, '_blank');
}

async function onUpdateFileSelected(uploadFile) {
    const file = uploadFile?.raw;
    uploadRef.value?.clearFiles();
    if (!file) return;

    const isZip =
        file.type === 'application/zip' ||
        file.type === 'application/x-zip-compressed' ||
        /\.zip$/i.test(file.name);
    if (!isZip) {
        ElMessage.error(t('settings.uploadUpdateNotZip'));
        return;
    }

    try {
        await ElMessageBox.confirm(
            t('settings.uploadUpdateConfirm', { name: file.name }),
            t('common.confirm'),
            { type: 'warning' }
        );
    } catch {
        return;
    }

    uploadingUpdate.value = true;
    try {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await http.post('/upload-update', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            timeout: 0,
        });
        if (data?.success) {
            ElMessage.success(data.message || t('common.success'));
        } else {
            ElMessage.error(data?.message || t('common.error'));
        }
    } catch (err) {
        ElMessage.error(err?.response?.data?.message || t('common.error'));
    } finally {
        uploadingUpdate.value = false;
    }
}

onMounted(fetchSettings);
</script>
