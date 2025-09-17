import { isDev } from '../utils/env';

import { GrocersListApi } from './GrocersListApi';
import { GrocersListApiMock } from './GrocersListApiMock';
import type { IGrocersListApi } from './IGrocersListApi';

let instance: IGrocersListApi;

export const getGrocersListApi = (): IGrocersListApi => {
  if (!instance) {
    instance = isDev() ? new GrocersListApiMock() : new GrocersListApi();
  }
  return instance;
};
