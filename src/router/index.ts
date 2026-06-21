import { createRouter, createWebHistory } from 'vue-router'
import AccountsPage from '@/pages/AccountsPage.vue'
import AccountDetailPage from '@/pages/AccountDetailPage.vue'

const routes = [
  {
    path: '/',
    name: 'accounts',
    component: AccountsPage,
  },
  {
    path: '/accounts/:id',
    name: 'account-detail',
    component: AccountDetailPage,
    props: true,
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
