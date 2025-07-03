import type {IGrocersListApi, MatchedLinks, MigrationStatus, LinkCountInfo, PostGatingOptions} from './IGrocersListApi'

const STORAGE_KEY = 'grocers_list_mock_state'

export interface GrocersListPluginState {
  apiKey: string
  autoRewriteEnabled: boolean
  useLinkstaLinks: boolean
  setupComplete: boolean
}

const getDefaultState = (): GrocersListPluginState => ({
  apiKey: 'mock-api-key-1234567890',
  autoRewriteEnabled: true,
  useLinkstaLinks: true,
  setupComplete: false,
})

export class GrocersListApiMock implements IGrocersListApi {
  getPostGatingOptions(_: number): Promise<PostGatingOptions> {
    return Promise.resolve({
      postGated: false,
      recipeCardGated: false
    });
  }

  updatePostGatingOptions(_1: number, _2: PostGatingOptions): Promise<void> {
    return Promise.resolve();
  }

  private delay(ms: number) {
    return new Promise((resolve) => setTimeout(resolve, ms))
  }

  private getStateFromStorage(): GrocersListPluginState {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? JSON.parse(raw) : getDefaultState()
  }

  private setStateToStorage(state: GrocersListPluginState) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  }

  async updateApiKey(apiKey: string) {
    console.log('🔧 Mock updateApiKey', apiKey)
    await this.delay(1000)
    const state = this.getStateFromStorage()
    state.apiKey = apiKey
    this.setStateToStorage(state)
  }

  async getState() {
    console.log('🔧 Mock getState')
    await this.delay(1000)
    return this.getStateFromStorage()
  }

  async markSetupComplete() {
    console.log('🔧 Mock markSetupComplete')
    await this.delay(1000)
    const state = this.getStateFromStorage()
    state.setupComplete = true
    this.setStateToStorage(state)
  }

  async updateAutoRewrite(enabled: boolean) {
    console.log('🔧 Mock updateAutoRewrite', enabled)
    await this.delay(1000)
    const state = this.getStateFromStorage()
    state.autoRewriteEnabled = enabled
    this.setStateToStorage(state)
  }

  async updateUseLinkstaLinks(enabled: boolean) {
    console.log('🔧 Mock updateUseLinkstaLinks', enabled)
    await this.delay(1000)
    const state = this.getStateFromStorage()
    state.useLinkstaLinks = enabled
    this.setStateToStorage(state)
  }

  async countMatchedLinks(): Promise<MatchedLinks> {
    console.log('🔧 Mock countMatchedLinks')
    await this.delay(1000)
    return {
      postsWithLinks: 3,
      totalLinks: 42,
    }
  }

  async triggerMigrate(): Promise<void> {
    console.log('🔧 Mock triggerMigrate')
    await this.delay(500)
    localStorage.setItem('grocers_list_mock_migration_started', 'true')
  }

  async triggerRecountLinks(): Promise<void> {
    console.log('🔧 Mock triggerRecountLinks')
    await this.delay(500)
    localStorage.setItem('grocers_list_mock_recount_started', 'true')
  }

  async clearSettings() {
    console.log('🔧 Mock clearSettings');
    await this.delay(500);
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem('grocers_list_mock_migration_started');
    localStorage.removeItem('grocers_list_mock_recount_started');
  }

  async getMigrationStatus(): Promise<MigrationStatus> {
    console.log('🔧 Mock getMigrationStatus');
    await this.delay(500);

    const started = localStorage.getItem('grocers_list_mock_migration_started');
    const randomProgress = Math.min(42, Math.floor(Math.random() * 42));
    const isComplete = randomProgress === 42;

    if (!started) {
      return {
        total: 42,
        processed: 0,
        remaining: 42,
        isComplete: false
      };
    }

    return {
      total: 42,
      processed: isComplete ? 42 : randomProgress,
      remaining: isComplete ? 0 : 42 - randomProgress,
      isComplete
    };
  }

  async getLinkCountInfo(): Promise<LinkCountInfo> {
    console.log('🔧 Mock getLinkCountInfo');
    await this.delay(500);

    const started = localStorage.getItem('grocers_list_mock_recount_started');
    const isRunning = Math.random() > 0.3; // Randomly decide if still running
    const randomProcessed = Math.floor(Math.random() * 25) + 5;
    const isComplete = !isRunning && randomProcessed >= 25;

    if (!started) {
      return {
        postsWithLinks: 0,
        totalLinks: 0,
        totalPosts: 25,
        lastCount: Date.now() - 7200000,
        isRunning: false,
        processedPosts: 0,
        isComplete: false
      };
    }

    return {
      postsWithLinks: 5,
      totalLinks: 50,
      totalPosts: 25,
      lastCount: Date.now(),
      isRunning,
      processedPosts: isComplete ? 25 : randomProcessed,
      isComplete
    };
  }
}
