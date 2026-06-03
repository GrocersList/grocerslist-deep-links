import type {
  IGrocersListApi,
  LinkCountInfo,
  SalesPageState,
} from './IGrocersListApi';

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

  async clearCache() {
    await this.post('grocers_list_clear_cache', {});
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

  async getSalesPageState(): Promise<SalesPageState> {
    const res = await this.post('grocers_list_get_sales_page_state', {});
    return res.data;
  }

  async createSalesPage(slug: string): Promise<SalesPageState> {
    const res = await this.post('grocers_list_create_sales_page', { slug });
    return res.data;
  }

  async regenerateSalesPage(slug: string): Promise<SalesPageState> {
    const res = await this.post('grocers_list_regenerate_sales_page', { slug });
    return res.data;
  }

  async addSalesPageToMenu(
    menuId: number,
    label: string
  ): Promise<SalesPageState> {
    const res = await this.post('grocers_list_add_sales_page_to_menu', {
      menuId: menuId.toString(),
      label,
    });
    return res.data;
  }

  async updateSalesPageMenuItemLabel(label: string): Promise<SalesPageState> {
    const res = await this.post(
      'grocers_list_update_sales_page_menu_item_label',
      { label }
    );
    return res.data;
  }

  async removeSalesPageFromMenu(): Promise<SalesPageState> {
    const res = await this.post('grocers_list_remove_sales_page_from_menu', {});
    return res.data;
  }

  async removeSalesPage(): Promise<SalesPageState> {
    const res = await this.post('grocers_list_remove_sales_page', {});
    return res.data;
  }
}
