import type { IGrocersListApi, LinkCountInfo } from './IGrocersListApi';

export class GrocersListApi implements IGrocersListApi {
  private async post(action: string, params: Record<string, string>) {
    const body = new URLSearchParams({
      action,
      _ajax_nonce: window.grocersList.nonces[action],
      ...params,
    });

    const res = await fetch(window.grocersList.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });

    const json = await res.json();
    if (!res.ok || !json.success) {
      throw new Error(`Failed action: ${action}`);
    }

    return json;
  }

  async updateApiKey(apiKey: string) {
    await this.post('grocers_list_update_api_key', { apiKey });
  }

  async getState() {
    const res = await this.post('grocers_list_get_state', {});
    return res.data;
  }

  async updateUseLinkstaLinks(enabled: boolean) {
    await this.post('grocers_list_update_use_linksta_links', {
      useLinkstaLinks: enabled ? '1' : '0',
    });
  }

  async triggerMigrate() {
    await this.post('grocers_list_trigger_migrate', {});
  }

  async clearSettings() {
    await this.post('grocers_list_clear_settings', {});
  }

  async getMigrationStatus() {
    const res = await this.post('grocers_list_get_migration_status', {});
    return res.data;
  }

  async getLinkCountInfo(): Promise<LinkCountInfo> {
    const res = await this.post('grocers_list_get_link_count_info', {});
    return res.data;
  }

  async getQueueStats() {
    const res = await this.post('grocerslist_get_queue_stats', {});
    return res.data;
  }

  async processQueue() {
    const res = await this.post('grocerslist_process_queue', {});
    return res.data;
  }

  async resetFailedPosts() {
    const res = await this.post('grocerslist_reset_failed_posts', {});
    return res.data;
  }

  async getUrlMappings(limit = 100) {
    const res = await this.post('grocerslist_get_url_mappings', {
      limit: limit.toString(),
    });
    return res.data;
  }

  async updateMembershipsEnabled(enabled: boolean) {
    console.log('🔧 updateMembershipsEnabled', enabled);
    await this.post('grocers_list_update_memberships_enabled', {
      enabled: enabled ? '1' : '0',
    });
  }
}
