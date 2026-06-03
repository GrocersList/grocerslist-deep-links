import type {
  IGrocersListApi,
  LinkCountInfo,
  MigrationStatus,
  ProcessQueueResult,
  QueueStats,
  ResetFailedResult,
  SalesPageState,
  UrlMapping,
} from './IGrocersListApi';

const STORAGE_KEY = 'grocers_list_mock_state';

export interface GrocersListPluginState {
  apiKey: string;
  useLinkstaLinks: boolean;
}

const getDefaultState = (): GrocersListPluginState => ({
  apiKey: 'mock-api-key-1234567890',
  useLinkstaLinks: true,
});

export class GrocersListApiMock implements IGrocersListApi {
  private delay(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  private getStateFromStorage(): GrocersListPluginState {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : getDefaultState();
  }

  private setStateToStorage(state: GrocersListPluginState) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  async updateApiKey(apiKey: string) {
    console.log('🔧 Mock updateApiKey', apiKey);
    await this.delay(1000);
    const state = this.getStateFromStorage();
    state.apiKey = apiKey;
    this.setStateToStorage(state);
  }

  async getState() {
    console.log('🔧 Mock getState');
    await this.delay(1000);
    return this.getStateFromStorage();
  }

  async updateUseLinkstaLinks(enabled: boolean) {
    console.log('🔧 Mock updateUseLinkstaLinks', enabled);
    await this.delay(1000);
    const state = this.getStateFromStorage();
    state.useLinkstaLinks = enabled;
    this.setStateToStorage(state);
  }

  async triggerMigrate(): Promise<void> {
    console.log('🔧 Mock triggerMigrate');
    await this.delay(500);
    localStorage.setItem('grocers_list_mock_migration_started', 'true');
  }

  async clearCache() {
    console.log('🔧 Mock clearCache');
    await this.delay(500);
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem('grocers_list_mock_clear_cache');
  }

  async clearSettings() {
    console.log('🔧 Mock clearSettings');
    await this.delay(500);
    localStorage.removeItem(STORAGE_KEY);
    // TODO: mock clearing other settings
  }

  async getMigrationStatus(): Promise<MigrationStatus> {
    console.log('🔧 Mock getMigrationStatus');
    await this.delay(500);

    const started = localStorage.getItem('grocers_list_mock_migration_started');
    const randomProgress = Math.min(42, Math.floor(Math.random() * 42));
    const isComplete = randomProgress === 42;

    if (!started) {
      return {
        isComplete: false,
        isRunning: false,
        lastMigrationStartedAt: 0,
        lastMigrationCompletedAt: 0,
      };
    }

    return {
      isComplete,
      isRunning: false,
      lastMigrationStartedAt: 0,
      lastMigrationCompletedAt: 0,
    };
  }

  async getLinkCountInfo(): Promise<LinkCountInfo> {
    console.log('🔧 Mock getLinkCountInfo');
    await this.delay(500);

    return {
      postsWithLinks: 5,
      totalPosts: 25,
      totalAmazonLinks: 50,
      totalMappedLinks: 0,
      totalUnmappedLinks: 0,
    };
  }

  async getQueueStats(): Promise<QueueStats> {
    console.log('🔧 Mock getQueueStats');
    await this.delay(400);

    const total = 15;
    const completed = Math.floor(Math.random() * 8);
    const failed = Math.floor(Math.random() * 3);
    const processing = Math.random() > 0.7 ? 1 : 0;
    const pending = total - completed - failed - processing;

    return {
      total,
      pending: Math.max(0, pending),
      processing,
      completed,
      failed,
      nextScheduledRun: new Date(Date.now() + 180000).toISOString(), // 3 minutes from now
    };
  }

  async processQueue(): Promise<ProcessQueueResult> {
    console.log('🔧 Mock processQueue');
    await this.delay(2000); // Simulate processing time

    const processed = Math.floor(Math.random() * 5) + 1;
    const errors = Math.random() > 0.8 ? 1 : 0;

    return { processed, errors };
  }

  async resetFailedPosts(): Promise<ResetFailedResult> {
    console.log('🔧 Mock resetFailedPosts');
    await this.delay(800);

    return { reset: Math.floor(Math.random() * 3) + 1 };
  }

  async getUrlMappings(limit = 100): Promise<UrlMapping[]> {
    console.log('🔧 Mock getUrlMappings', limit);
    await this.delay(600);

    const mappings: UrlMapping[] = [];
    const count = Math.min(limit, Math.floor(Math.random() * 20) + 5);

    for (let i = 0; i < count; i++) {
      mappings.push({
        id: i + 1,
        original_url: `https://amazon.com/dp/B${String(Math.random()).substr(2, 6)}`,
        linksta_url: `https://linksta.io/${String(Math.random()).substr(2, 8)}`,
        link_hash: String(Math.random()).substr(2, 8),
        created_at: new Date(
          Date.now() - Math.random() * 7 * 24 * 60 * 60 * 1000
        ).toISOString(),
      });
    }

    return mappings;
  }

  async updateMembershipsEnabled(enabled: boolean) {
    console.log('🔧 Mock updateMembershipsEnabled', enabled);
    await this.delay(500);
  }

  private getSalesPageStateFromStorage(): SalesPageState {
    const raw = localStorage.getItem('grocers_list_mock_sales_page');
    if (raw) return JSON.parse(raw);
    return {
      page: null,
      menuItemId: 0,
      menuItemLabel: '',
      menus: [
        { id: 1, name: 'Primary Menu' },
        { id: 2, name: 'Footer Menu' },
      ],
      primaryMenuId: 1,
      isBlockTheme: false,
      menuEditorUrl: '#nav-menus',
      siteEditorUrl: '#site-editor',
      supportsPattern: true,
    };
  }

  private setSalesPageStateToStorage(state: SalesPageState) {
    localStorage.setItem('grocers_list_mock_sales_page', JSON.stringify(state));
  }

  async getSalesPageState(): Promise<SalesPageState> {
    console.log('🔧 Mock getSalesPageState');
    await this.delay(300);
    return this.getSalesPageStateFromStorage();
  }

  async createSalesPage(slug: string): Promise<SalesPageState> {
    console.log('🔧 Mock createSalesPage', slug);
    await this.delay(500);
    const state = this.getSalesPageStateFromStorage();
    state.page = {
      id: 42,
      slug: slug || 'membership',
      title: 'Membership',
      status: 'draft',
      editUrl: '#edit',
      previewUrl: '#preview',
      viewUrl: `/${slug || 'membership'}`,
    };
    this.setSalesPageStateToStorage(state);
    return state;
  }

  async regenerateSalesPage(slug: string): Promise<SalesPageState> {
    console.log('🔧 Mock regenerateSalesPage', slug);
    await this.delay(500);
    const state = this.getSalesPageStateFromStorage();
    state.menuItemId = 0;
    state.menuItemLabel = '';
    state.page = {
      id: 43,
      slug: slug || 'membership',
      title: 'Membership',
      status: 'draft',
      editUrl: '#edit',
      previewUrl: '#preview',
      viewUrl: `/${slug || 'membership'}`,
    };
    this.setSalesPageStateToStorage(state);
    return state;
  }

  async addSalesPageToMenu(
    menuId: number,
    label: string
  ): Promise<SalesPageState> {
    console.log('🔧 Mock addSalesPageToMenu', menuId, label);
    await this.delay(400);
    const state = this.getSalesPageStateFromStorage();
    state.menuItemId = Math.floor(Math.random() * 10000) + 1;
    state.menuItemLabel = label || 'Membership';
    this.setSalesPageStateToStorage(state);
    return state;
  }

  async updateSalesPageMenuItemLabel(label: string): Promise<SalesPageState> {
    console.log('🔧 Mock updateSalesPageMenuItemLabel', label);
    await this.delay(400);
    const state = this.getSalesPageStateFromStorage();
    state.menuItemLabel = label;
    this.setSalesPageStateToStorage(state);
    return state;
  }

  async removeSalesPageFromMenu(): Promise<SalesPageState> {
    console.log('🔧 Mock removeSalesPageFromMenu');
    await this.delay(400);
    const state = this.getSalesPageStateFromStorage();
    state.menuItemId = 0;
    state.menuItemLabel = '';
    this.setSalesPageStateToStorage(state);
    return state;
  }

  async removeSalesPage(): Promise<SalesPageState> {
    console.log('🔧 Mock removeSalesPage');
    await this.delay(400);
    const state = this.getSalesPageStateFromStorage();
    state.page = null;
    state.menuItemId = 0;
    state.menuItemLabel = '';
    this.setSalesPageStateToStorage(state);
    return state;
  }
}
